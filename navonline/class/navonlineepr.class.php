<?php
/* Copyright (C) 2026
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
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    htdocs/custom/navonline/class/navonlineepr.class.php
 * \ingroup navonline
 * \brief   EPR (kiterjesztett gyártói felelősség) and product fee (termékdíj) of customer invoices.
 *  - codes (llx_navonline_epr_code): type E (EPR) / T (termékdíj), KT code, rate per unit (HUF / kg), validity;
 *  - products (llx_navonline_epr_product): the codes switched on for a product, with a factor = fee base per product
 *    unit (kg per piece);
 *  - lines (llx_navonline_epr_line): at validation of a customer invoice for Hungarian domestic sales (issuer and
 *    customer in Hungary; standard, replacement, situation invoices and credit notes; not deposit invoices nor
 *    proforma), one row per product line and code: base = quantity x factor, amount = base x rate of the delivery
 *    date (teljesítés), never updated. A credit note line takes the code, factor and rate of the original invoice
 *    line (fk_source) and a negative base. Missing rate: no row, reported (no unreliable total).
 */

/**
 * EPR / product fee
 */
class NavOnlineEpr
{
	const TYPES = array('E' => 'EPR díj', 'T' => 'Termékdíj');

	/** @var DoliDB */
	public $db;

	/** @var string[] */
	public $errors = array();

	/** @var string[] Lines left out, with the reason */
	public $warnings = array();

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Code row valid on a day (the latest start not after it).
	 *
	 * @param string $type   E | T
	 * @param string $ktCode KT code
	 * @param string $date   Y-m-d
	 * @return object|null rowid, kt_code, label, rate, unit
	 */
	public function codeOn($type, $ktCode, $date)
	{
		$d = $this->db->escape($date);
		$sql = 'SELECT rowid, type, kt_code, label, rate, unit FROM '.$this->db->prefix().'navonline_epr_code';
		$sql .= " WHERE entity IN (".getEntity('invoice').") AND active = 1 AND type = '".$this->db->escape($type)."' AND kt_code = '".$this->db->escape($ktCode)."'";
		$sql .= " AND (date_start IS NULL OR date_start <= '".$d."') AND (date_end IS NULL OR date_end >= '".$d."') ORDER BY date_start DESC, rowid DESC LIMIT 1";
		$res = $this->db->query($sql);
		return $res ? ($this->db->fetch_object($res) ?: null) : null;
	}

	/**
	 * @param int $productId Product
	 * @return array<int,object> Codes switched on for the product (type, kt_code, factor)
	 */
	public function productCodes($productId)
	{
		$out = array();
		$res = $this->db->query('SELECT rowid, type, kt_code, factor FROM '.$this->db->prefix().'navonline_epr_product WHERE entity IN ('.getEntity('product').') AND fk_product = '.((int) $productId).' ORDER BY type, kt_code');
		while ($res && ($o = $this->db->fetch_object($res))) {
			$out[] = $o;
		}
		return $out;
	}

	/**
	 * The invoice is a Hungarian domestic sale with fee data: Hungarian issuer and customer, invoice type with goods.
	 *
	 * @param Facture $invoice Invoice (with thirdparty fetched or fetchable)
	 * @return bool
	 */
	public function concerns($invoice)
	{
		global $mysoc;

		if (empty($mysoc->country_code) || $mysoc->country_code !== 'HU' || !in_array((int) $invoice->type, array(0, 1, 2, 5), true)) {
			return false;
		}
		if (empty($invoice->thirdparty) || empty($invoice->thirdparty->id)) {
			$invoice->fetch_thirdparty();
		}
		return !empty($invoice->thirdparty) && ($invoice->thirdparty->country_code ?? '') === 'HU';
	}

	/**
	 * Fee rows of the invoice lines by the rules (not stored): what record() stores, and the preview of a draft.
	 *
	 * @param Facture $invoice Invoice (lines loaded or loadable)
	 * @return array<int,array<string,mixed>> Rows: fk_facturedet, fk_product, product_ref, type, kt_code, label, qty, factor,
	 *                                        base, unit, rate, amount, fk_source, delivery_date, invoice_date
	 */
	public function compute($invoice)
	{
		$this->warnings = array();
		if (!$this->concerns($invoice)) {
			return array();
		}
		if (empty($invoice->lines)) {
			$invoice->fetch_lines();
		}
		$date = $invoice->date_pointoftax ? dol_print_date($invoice->date_pointoftax, '%Y-%m-%d') : dol_print_date($invoice->date ?: dol_now(), '%Y-%m-%d');
		$isCredit = (int) $invoice->type === 2;
		$source = $isCredit && $invoice->fk_facture_source ? $this->linesOf((int) $invoice->fk_facture_source) : array();
		$out = array();
		foreach ($invoice->lines as $l) {
			if (empty($l->fk_product) || (int) $l->product_type !== 0 || (float) $l->qty == 0.0 || !empty($l->fk_remise_except)) {
				continue;
			}
			foreach ($this->productCodes((int) $l->fk_product) as $pc) {
				$src = null;
				foreach ($source as $s) {
					if ((int) $s->fk_product === (int) $l->fk_product && $s->type === $pc->type && $s->kt_code === $pc->kt_code) {
						$src = $s;
						break;
					}
				}
				if ($src) {
					$label = $src->label;
					$factor = (float) $src->factor;
					$rate = (float) $src->rate;
					$unit = $src->unit;
				} else {
					$code = $this->codeOn($pc->type, $pc->kt_code, $date);
					if (!$code) {
						$this->warnings[] = ($l->product_ref ?? $l->fk_product).': nincs érvényes '.(self::TYPES[$pc->type] ?? $pc->type).' díjtétel ('.$pc->kt_code.', '.$date.')';
						continue;
					}
					$label = $code->label;
					$factor = (float) $pc->factor;
					$rate = (float) $code->rate;
					$unit = $code->unit;
				}
				$qty = $isCredit ? -abs((float) $l->qty) : (float) $l->qty;
				$base = $qty * $factor;
				$out[] = array('fk_facturedet' => (int) $l->id, 'fk_product' => (int) $l->fk_product, 'product_ref' => (string) ($l->product_ref ?? ''), 'type' => $pc->type, 'kt_code' => $pc->kt_code,
					'label' => (string) $label, 'qty' => $qty, 'factor' => $factor, 'base' => $base, 'unit' => (string) $unit, 'rate' => $rate, 'amount' => (float) price2num($base * $rate, 'MT'),
					'fk_source' => $src ? (int) $src->rowid : null, 'delivery_date' => $invoice->date_pointoftax ? $date : null, 'invoice_date' => dol_print_date($invoice->date ?: dol_now(), '%Y-%m-%d'));
			}
		}
		return $out;
	}

	/**
	 * Records the fee data of the invoice lines (again: earlier rows of the invoice are replaced).
	 *
	 * @param Facture $invoice Invoice being validated (lines loaded)
	 * @param User    $user    User
	 * @return int Rows recorded, -1 on database error
	 */
	public function record($invoice, $user)
	{
		$this->errors = array();
		$this->remove((int) $invoice->id);
		$n = 0;
		foreach ($this->compute($invoice) as $r) {
			$sql = 'INSERT INTO '.$this->db->prefix().'navonline_epr_line (entity, fk_facture, fk_facturedet, fk_product, invoice_type, invoice_date, delivery_date, type, kt_code, label, qty, factor, base, unit, rate, amount, fk_source, datec, fk_user)';
			$sql .= ' VALUES ('.((int) $invoice->entity).', '.((int) $invoice->id).', '.$r['fk_facturedet'].', '.$r['fk_product'].', '.((int) $invoice->type).", '".$this->db->escape($r['invoice_date'])."', ";
			$sql .= ($r['delivery_date'] ? "'".$this->db->escape($r['delivery_date'])."'" : 'NULL').", '".$this->db->escape($r['type'])."', '".$this->db->escape($r['kt_code'])."', '".$this->db->escape($r['label'])."', ";
			$sql .= price2num($r['qty']).', '.price2num($r['factor']).', '.price2num($r['base']).", '".$this->db->escape($r['unit'])."', ".price2num($r['rate']).', '.price2num($r['amount']).', '.($r['fk_source'] ? (int) $r['fk_source'] : 'NULL').", '".$this->db->idate(dol_now())."', ".((int) $user->id).')';
			if (!$this->db->query($sql)) {
				$this->errors[] = $this->db->lasterror();
				return -1;
			}
			$n++;
		}
		return $n;
	}

	/**
	 * Fee data for the invoice document: the recorded rows of a validated invoice, the computed ones of a draft.
	 *
	 * @param Facture $invoice Invoice
	 * @return array{lines:array<int,array<int,array<string,mixed>>>,summary:array<string,array<string,mixed>>}
	 *         lines: by invoice line id; summary: by type, code and rate (label, unit, rate, base, amount, note)
	 */
	public function forDocument($invoice)
	{
		$rows = array();
		if ((int) ($invoice->status ?? $invoice->statut ?? 0) > 0) {
			foreach ($this->linesOf((int) $invoice->id) as $o) {
				$rows[] = get_object_vars($o);
			}
		} else {
			$rows = $this->compute($invoice);
		}
		$notes = array();
		$res = $this->db->query('SELECT type, kt_code, note FROM '.$this->db->prefix().'navonline_epr_code WHERE entity IN ('.getEntity('invoice').") AND note IS NOT NULL AND note <> '' ORDER BY date_start");
		while ($res && ($o = $this->db->fetch_object($res))) {
			$notes[$o->type.'|'.$o->kt_code] = (string) $o->note;
		}
		$out = array('lines' => array(), 'summary' => array());
		foreach ($rows as $r) {
			$r['note'] = $notes[$r['type'].'|'.$r['kt_code']] ?? '';
			$out['lines'][(int) $r['fk_facturedet']][] = $r;
			$k = $r['type'].'|'.$r['kt_code'].'|'.price2num((float) $r['rate']);
			if (!isset($out['summary'][$k])) {
				$out['summary'][$k] = array('type' => $r['type'], 'kt_code' => $r['kt_code'], 'label' => $r['label'], 'unit' => $r['unit'], 'rate' => (float) $r['rate'], 'base' => 0.0, 'amount' => 0.0);
			}
			$out['summary'][$k]['base'] += (float) $r['base'];
			$out['summary'][$k]['amount'] += (float) $r['amount'];
		}
		return $out;
	}

	/**
	 * @param int $invoiceId Invoice
	 * @return array<int,object> Fee rows of the invoice
	 */
	public function linesOf($invoiceId)
	{
		$out = array();
		$res = $this->db->query('SELECT * FROM '.$this->db->prefix().'navonline_epr_line WHERE fk_facture = '.((int) $invoiceId).' ORDER BY rowid');
		while ($res && ($o = $this->db->fetch_object($res))) {
			$out[] = $o;
		}
		return $out;
	}

	/**
	 * Fee rows of the invoice, by code (for the invoice document).
	 *
	 * @param int $invoiceId Invoice
	 * @return array<string,array<string,mixed>> 'type|kt_code|rate' => type, kt_code, label, unit, rate, base, amount
	 */
	public function summaryOf($invoiceId)
	{
		$out = array();
		foreach ($this->linesOf($invoiceId) as $o) {
			$k = $o->type.'|'.$o->kt_code.'|'.price2num((float) $o->rate);
			if (!isset($out[$k])) {
				$out[$k] = array('type' => $o->type, 'kt_code' => $o->kt_code, 'label' => $o->label, 'unit' => $o->unit, 'rate' => (float) $o->rate, 'base' => 0.0, 'amount' => 0.0);
			}
			$out[$k]['base'] += (float) $o->base;
			$out[$k]['amount'] += (float) $o->amount;
		}
		return $out;
	}

	/**
	 * Removes the fee rows of an invoice (back to draft, deleted).
	 *
	 * @param int $invoiceId Invoice
	 * @return void
	 */
	public function remove($invoiceId)
	{
		$this->db->query('DELETE FROM '.$this->db->prefix().'navonline_epr_line WHERE fk_facture = '.((int) $invoiceId));
	}
}
