<?php
/* Copyright (C) 2026 Alu-Logic Kft.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    navonline/class/navonlinereturn.class.php
 * \ingroup navonline
 * \brief   Visszáru: stock movements of the product lines of a credit note
 */

require_once DOL_DOCUMENT_ROOT.'/product/stock/class/mouvementstock.class.php';

/**
 * Returned goods of a credit note, on request (a credit note may be a price reduction only):
 *  - customer credit note (Facture, type 2): goods come back into the warehouse ("Visszáru bevételezése");
 *  - supplier credit note (FactureFournisseur, type 2): goods go back to the supplier ("Visszáru kiadása").
 * One movement per product line, quantity of the line, at the current weighted average price (the average price
 * does not change), origin = the credit note, inventory code VISSZARU-<ref>; a kit moves its components (as a
 * shipment does). Done once per credit note.
 */
class NavOnlineReturn
{
	/** @var DoliDB */
	private $db;

	/** @var string[] */
	public $errors = array();

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * @param CommonInvoice $invoice Invoice
	 * @return bool Customer or supplier credit note, validated
	 */
	public static function isCandidate($invoice)
	{
		return is_object($invoice) && in_array($invoice->element, array('facture', 'invoice_supplier'), true)
			&& (int) $invoice->type === 2 && (int) $invoice->status >= 1 && isModEnabled('stock');
	}

	/**
	 * @param CommonInvoice $invoice Credit note
	 * @return bool true = customer credit note (goods in), false = supplier credit note (goods out)
	 */
	public static function isIncoming($invoice)
	{
		return $invoice->element === 'facture';
	}

	/**
	 * Product lines with stock: fk_product, quantity, label.
	 *
	 * @param CommonInvoice $invoice Credit note
	 * @return array<int,array{fk_product:int,qty:float,ref:string}>
	 */
	public function lines($invoice)
	{
		if (empty($invoice->lines) && method_exists($invoice, 'fetch_lines')) {
			$invoice->fetch_lines();
		}
		$out = array();
		foreach ($invoice->lines as $l) {
			$type = isset($l->product_type) ? (int) $l->product_type : (int) ($l->fk_product_type ?? 0);
			if (empty($l->fk_product) || $type !== 0 || !empty($l->fk_remise_except) || (float) $l->qty == 0) {
				continue;
			}
			$out[] = array('fk_product' => (int) $l->fk_product, 'qty' => abs((float) $l->qty), 'ref' => (string) ($l->product_ref ?? $l->ref ?? ''));
		}
		return $out;
	}

	/**
	 * @param CommonInvoice $invoice Credit note
	 * @return object|null First movement already made for it (datem, fk_entrepot), null if none
	 */
	public function done($invoice)
	{
		$sql = 'SELECT MIN(m.datem) AS datem, MIN(m.fk_entrepot) AS fk_entrepot, COUNT(*) AS nb FROM '.$this->db->prefix().'stock_mouvement m';
		$sql .= " WHERE m.origintype = '".$this->db->escape($invoice->element)."' AND m.fk_origin = ".((int) $invoice->id);
		$res = $this->db->query($sql);
		$o = $res ? $this->db->fetch_object($res) : null;
		return ($o && (int) $o->nb > 0) ? $o : null;
	}

	/**
	 * @return array<int,string> Open warehouses id => label
	 */
	public function warehouses()
	{
		$out = array();
		$res = $this->db->query('SELECT rowid, ref FROM '.$this->db->prefix().'entrepot WHERE statut = 1 AND entity IN ('.getEntity('stock').') ORDER BY ref');
		while ($res && ($o = $this->db->fetch_object($res))) {
			$out[(int) $o->rowid] = $o->ref;
		}
		return $out;
	}

	/**
	 * @return int Default warehouse (MAIN_DEFAULT_WAREHOUSE when open, else the only one, else 0)
	 */
	public function defaultWarehouse()
	{
		$all = $this->warehouses();
		$def = getDolGlobalInt('MAIN_DEFAULT_WAREHOUSE');
		if ($def && isset($all[$def])) {
			return $def;
		}
		return count($all) === 1 ? (int) key($all) : 0;
	}

	/**
	 * Make the movements (one transaction: all or nothing). A supplier return cannot take the stock below zero
	 * (STOCK_DISALLOW_NEGATIVE_TRANSFER): then nothing is moved and the error names the product.
	 *
	 * @param CommonInvoice $invoice     Credit note
	 * @param int           $warehouseId Warehouse
	 * @param User          $user        User
	 * @return int Number of movements, <0 on error
	 */
	public function run($invoice, $warehouseId, $user)
	{
		$this->errors = array();
		if (!self::isCandidate($invoice)) {
			$this->errors[] = 'Csak jóváhagyott jóváíró számlához';
			return -1;
		}
		if ($this->done($invoice)) {
			$this->errors[] = 'A visszáru készletmozgása már elkészült';
			return -1;
		}
		$wh = $this->warehouses();
		if (!isset($wh[(int) $warehouseId])) {
			$this->errors[] = 'Válassz raktárt';
			return -1;
		}
		$lines = $this->lines($invoice);
		if (!$lines) {
			$this->errors[] = 'A jóváírón nincs készletes termék tétel';
			return -1;
		}
		$in = self::isIncoming($invoice);
		$label = ($in ? 'Visszáru bevételezése' : 'Visszáru kiadása').' - '.$invoice->ref.(!empty($invoice->ref_supplier) ? ' ('.$invoice->ref_supplier.')' : '');
		$code = 'VISSZARU-'.$invoice->ref;

		$this->db->begin();
		$n = 0;
		foreach ($lines as $l) {
			$res = $this->db->query('SELECT pmp FROM '.$this->db->prefix().'product WHERE rowid = '.((int) $l['fk_product']));
			$o = $res ? $this->db->fetch_object($res) : null;
			$pmp = $o ? (float) $o->pmp : 0;
			$mv = new MouvementStock($this->db);
			$mv->setOrigin($invoice->element, (int) $invoice->id);
			$r = $in
				? $mv->reception($user, $l['fk_product'], (int) $warehouseId, $l['qty'], $pmp, $label, '', '', '', '', 0, $code)
				: $mv->livraison($user, $l['fk_product'], (int) $warehouseId, $l['qty'], $pmp, $label, '', '', '', '', 0, $code);
			// 0 = kit: core moves its components only (the kit itself has no stock), not an error
			if ($r < 0) {
				$this->db->rollback();
				$msg = (string) ($mv->error ?: implode(' ', (array) $mv->errors));
				$this->errors[] = ($l['ref'] !== '' ? $l['ref'].': ' : '').trim(html_entity_decode($msg, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
				return -1;
			}
			$n++;
		}
		$this->db->commit();
		return $n;
	}
}
