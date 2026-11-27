# Dolibarr egyedi modulok – NAV Online Számla / Dolibarr custom modules – NAV Online Invoice

[Magyar](#magyar) · [English](#english)

---

## Magyar

Ez a tároló a Dolibarr `htdocs/custom` mappájának tartalma. Két modult tartalmaz:

| Modul | Mappa | Leírás |
|---|---|---|
| **NavOnline** (500120) | `navonline/` | NAV Online Számla 3.0 adatszolgáltatás: számla-XML előállítása és XSD-ellenőrzése, beküldés és státuszlekérdezés, bejövő számlák lekérdezése, MNB-árfolyamok, VIES/OSS, magyar PDF-sablonok (számla, ajánlat, rendelés, díjbekérő, szállítólevél, bevételezés stb.). |
| **Mentes** (500121) | `mentes/` | Kijelentkezéskor elindít egy Windows ütemezett feladatot (alapból `Dolibarr mentes`), amely a biztonsági mentést végzi. |

### Követelmények

- Dolibarr 19 vagy újabb (a fejlesztés 24.0-n történt)
- PHP 8.1 vagy újabb, `curl`, `openssl`, `dom`, `libxml` és `mbstring` kiterjesztéssel
- Composer
- A NAV Online Számla rendszerben regisztrált **technikai felhasználó** (teszt- és/vagy éles környezetben)
- A NAV 3.0 XSD sémák (`invoiceData.xsd`, `invoiceBase.xsd`, az OSA 3.0 csomagból)
- A Mentes modulhoz: Windows-szerver, engedélyezett `exec()` és egy létrehozott ütemezett feladat

### Telepítés

1. **Fájlok másolása.** Klónozd a tárolót, és másold (vagy linkeld) a `navonline/` és `mentes/` mappát a Dolibarr `htdocs/custom/` mappájába:

   ```bash
   git clone https://github.com/polostibor/dolibarr-nav-online-szamla.git
   cp -r dolibarr-nav-online-szamla/navonline dolibarr-nav-online-szamla/mentes /utvonal/dolibarr/htdocs/custom/
   ```

2. **A custom mappa engedélyezése.** A `htdocs/conf/conf.php` fájlban legyen beállítva:

   ```php
   $dolibarr_main_url_root_alt = '/custom';
   $dolibarr_main_document_root_alt = '/utvonal/dolibarr/htdocs/custom';
   ```

3. **Függőségek telepítése** (a `vendor/` mappa nincs a tárolóban):

   ```bash
   cd htdocs/custom/navonline
   composer install --no-dev
   ```

4. **NAV-hozzáférés beállítása.** A `navonline/config/` mappában másold le a mintafájlt, és töltsd ki kézzel a technikai felhasználó adataival (felhasználónév, jelszó, adószám első 8 jegye, aláírókulcs, cserekulcs):

   ```bash
   cp config/credentials.php.example      config/credentials.php        # tesztkörnyezet
   cp config/credentials.prod.php.example config/credentials.prod.php   # éles környezet (csak ha kell)
   ```

   Ezek a fájlok a `.gitignore`-ban szerepelnek: **soha ne commitold őket**, és tartalmukat ne másold chatbe, jegybe vagy naplóba. Webszerveren korlátozd az olvasási jogot a webszerver felhasználójára.

5. **XSD sémák.** Töltsd le a NAV OSA 3.0 sémákat, és add meg a mappájukat a modul beállításainál (`NAVONLINE_XSD_DIR`).

6. **Modulok bekapcsolása.** *Beállítás → Modulok/Alkalmazások*: kapcsold be a **NavOnline** (és szükség esetén a **Mentes**) modult. A bekapcsolás létrehozza az adatbázistáblákat (`sql/` mappa) és az ütemezett feladatokat.

7. **Modulbeállítások** (*NavOnline → Beállítás*): szoftveradatok (softwareId, név, verzió, fejlesztő), számla megjelenési formája, pénzforgalmi elszámolás, kisadózó, XSD-mappa, riasztási e-mail címek, környezet (teszt / éles), próbaüzem (dry-run). Ugyanitt kipróbálható a kapcsolat és a riasztó e-mail.
   - Devizás számla jóváhagyásakor a modul a teljesítés napján érvényes MNB-árfolyamot állítja be (Áfa tv. 80. §; közösségen belüli értékesítésnél a számla keltéét). Kikapcsolás: `NAVONLINE_RATE_AT_TAX_POINT = 0` (*Beállítás → Egyéb beállítások*).
   - Adószám nélküli, nem ÁFA-alany belföldi szervezetnél a partner lapján jelöld be a **Nem ÁFA-alany (adószám nélküli szervezet)** mezőt.

8. **Ütemezett feladatok** (*Beállítás → Ütemezett feladatok*):
   - `NavOnlineSendQueue` – beküldési sor feldolgozása 5 percenként (alapból kikapcsolva),
   - `NavOnlineMnbRates` – napi MNB-árfolyamok (bekapcsolt `multicurrency` modul kell hozzá),
   - `NavOnlineInboundSync` – bejövő számlák napi listája (csak lekérdezés).

   A Dolibarr belső ütemezője helyett az operációs rendszer ütemezője is futtathatja őket:

   ```bash
   php htdocs/custom/navonline/scripts/navonline_cron.php
   ```

9. **Árfolyam-pontosság (ajánlott).** Ha devizás számlákat is kiállítasz, futtasd le kézzel a `navonline/sql/core_multicurrency_tx_precision.sql` szkriptet, így a `multicurrency_tx` oszlopok 12 tizedesjegyesek lesznek. Dolibarr-frissítés után ellenőrizd, és ha kell, futtasd újra.

10. **PDF-sablonok.** *Beállítás → Modulok → Számlák / Ajánlatok / Rendelések …* oldalon válaszd ki a magyar sablonokat (pl. `sponge_hu`, `cyan_hu`, `espadon_hu`).

11. **Mentes modul (opcionális).** Hozz létre egy Windows ütemezett feladatot a mentő szkripttel (alapnév: `Dolibarr mentes`; más név a `MENTES_TASK_NAME` állandóval adható meg), majd kapcsold be a modult. Kijelentkezéskor a modul a `schtasks /Run` paranccsal indítja a feladatot, a kijelentkezést nem lassítja.

### Bankkivonat importálása

*Bank → Bankkivonat importálása*: a bank havi kivonata (camt.053 XML) vagy a számlatörténet (XML) feltölthető. A bankszámlát a modul a fájlból ismeri fel, és ugyanaz a tétel akkor sem kerül be kétszer, ha mindkét fájlt beolvasod.

- A tételeket a modul a Dolibarrban már rögzített banki tételekkel párosítja: azonos összeg, ±10 nap, és ha egyértelmű, a partner bankszámlaszáma vagy a közleményben szereplő számlaszám alapján. A havi kivonat a párosított tételeket a kivonatszámmal egyeztetettnek is jelöli, és ellenőrzi a nyitó- és záróegyenleget.
- A párosítatlan tételeknél (bankköltség, kártyás vásárlás, saját számlák közötti átvezetés) a felkínált rögzítést tételenként te hagyod jóvá.

### Adóhatósági ellenőrzési adatszolgáltatás

*Számlázás → Vevői számlák → Adóhatósági ellenőrzési adatszolgáltatás*. Ezt a funkciót a 23/2014. (VI. 30.) NGM rendelet 11/A. §-a írja elő, és csak adminisztrátor éri el.

- Minden vevői számla jóváhagyásakor a modul elmenti a számla kiállításkori adatait NAV Online Számla invoiceData 3.0 XML-ként, XSD-ellenőrzéssel és ellenőrzőösszeggel. Magánszemély vevőnél a név és a cím is benne van; a NAV-nak beküldött adatban nincs benne. OSS-számlánál a külföldi ÁFA-kulccsal menti. A proformáról nem készül mentés.
- Kiválasztás kétféleképpen lehetséges: a kibocsátás dátuma szerint, vagy sorozat és számlasorszám-tartomány szerint. Mindkét végpont beletartozik. Letöltés előtt előnézet mutatja a darabszámot, az összegeket és az állapotokat, valamint a sorszám-hézagokat.
- A letöltött ZIP számlánként egy XML-t tartalmaz, továbbá a `jegyzek.xml` és a `jegyzek.csv` jegyzéket, és ha van mit jelezni, egy `hibalista.txt`-t. Ha egy számla adata hibás vagy hiányzik, vagy hézag van a sorszámokban, a csomag „HIANYOS” jelölést kap. A modul minden exportot naplóz. Az export nem küld adatot a NAV-nak.
- A funkció bevezetése előtt jóváhagyott számlákhoz a mentést a `php scripts/navonline_x01_backfill.php --run` paranccsal lehet pótolni. Ezek a mentések „nem kiállításkori” jelölést kapnak.

### Partneradatok a NAV-ból

Új partner rögzítésekor vagy szerkesztésekor a magyar adószám beírása után a modul lekérdezi a NAV Online Számla rendszerét (queryTaxpayer, csak olvasó lekérdezés), és kitölti az üres mezőket: név (a NAV rövid neve), teljes név (alternatív név), teljes adószám, áfacsoport-azonosító, cím, irányítószám, város.

- Ha egy mező már ki van töltve, és a NAV mást ad, a modul nem írja felül: mellé írja a NAV-adatot egy „Átvétel” linkkel.
- Ha az adószám nem létezik, vagy a NAV szerint nem érvényes, ezt az adószám mező alatt jelzi.
- Környezet: `NAVONLINE_TAXPAYER_ENVIRONMENT` (alapból éles, a számlabeküldéstől független); kikapcsolás: `NAVONLINE_TAXPAYER_LOOKUP = 0`. Partner-létrehozási jog kell hozzá.

### EPR és termékdíj

*Beállítás → Modulok → NAV Online Számla → EPR / termékdíj*: díjkódok (EPR vagy termékdíj, KT-kód, díjtétel, mértékegység, érvényesség). A termékkártya *EPR* fülén kapcsolható be a díjkód a termékre, szorzóval (díjalap egységenként, pl. kg/db).

- A vevői számla jóváhagyásakor a modul számlasoronként rögzíti a díjat: díjalap = mennyiség × szorzó, díj = díjalap × a teljesítés napján érvényes díjtétel. Csak magyar belföldi értékesítésnél (magyar kiállító és vevő), számlánál, helyesbítőnél és jóváírónál; előlegszámlánál és pro formánál nem.
- A jóváíró az eredeti számla díjadatával számol, negatív díjalappal. Ha nincs érvényes díjtétel, a sor kimarad, és a modul figyelmeztet; a jóváhagyást ez nem akadályozza meg. Új díjtétel új sor az első napjától, így a korábbi számlák díja nem változik.
- A számlán (sponge_hu sablon) a díjköteles sor alatt és a végösszeg alatt külön blokkban látszik a díj. Tájékoztató adat: benne van az árban, a végösszeget nem változtatja.

### Élesítés előtt

- Előbb a **tesztkörnyezetben** (`https://api-test.onlineszamla.nav.gov.hu/invoiceService/v3`) ellenőrizd a beküldést.
- A tesztkörnyezet `queryTaxpayer` válasza hibás ÁFA-kódokat adhat: ezeket ne másold át éles adatokba.
- Éles indulás előtt kapcsold be a próbaüzemet (dry-run), állítsd be az élesítés dátumát (`NAVONLINE_GOLIVE_DATE`), és csak ezután válts éles környezetre.
- Éles induláskor kapcsold be az `INVOICE_CAN_NEVER_BE_REMOVED` beállítást: kiállított számla nem törölhető, csak sztornózható. A próbaszámlákat előtte töröld.

---

## English

This repository holds the contents of Dolibarr's `htdocs/custom` folder. It contains two modules:

| Module | Folder | Description |
|---|---|---|
| **NavOnline** (500120) | `navonline/` | Hungarian NAV Online Invoice 3.0 reporting: builds the invoice XML and validates it against the XSD schemas, sends invoices and queries their status, queries inbound invoices, downloads MNB exchange rates, handles VIES/OSS, and provides Hungarian PDF templates (invoice, proposal, order, pro forma, delivery note, reception, etc.). |
| **Mentes** (500121) | `mentes/` | Starts a Windows scheduled task (default name `Dolibarr mentes`) at user logout to run the backup. |

### Requirements

- Dolibarr 19 or later (developed on 24.0)
- PHP 8.1 or later with the `curl`, `openssl`, `dom`, `libxml` and `mbstring` extensions
- Composer
- A **technical user** registered in NAV Online Invoice (test and/or production environment)
- The NAV 3.0 XSD schemas (`invoiceData.xsd`, `invoiceBase.xsd`, from the OSA 3.0 package)
- For Mentes: a Windows server, `exec()` enabled, and a scheduled task you have created

### Installation

1. **Copy the files.** Clone the repository and copy (or link) the `navonline/` and `mentes/` folders into Dolibarr's `htdocs/custom/` folder:

   ```bash
   git clone https://github.com/polostibor/dolibarr-nav-online-szamla.git
   cp -r dolibarr-nav-online-szamla/navonline dolibarr-nav-online-szamla/mentes /path/to/dolibarr/htdocs/custom/
   ```

2. **Enable the custom folder.** In `htdocs/conf/conf.php`, set:

   ```php
   $dolibarr_main_url_root_alt = '/custom';
   $dolibarr_main_document_root_alt = '/path/to/dolibarr/htdocs/custom';
   ```

3. **Install the dependencies.** The `vendor/` folder isn't in the repository, so run:

   ```bash
   cd htdocs/custom/navonline
   composer install --no-dev
   ```

4. **Set up NAV access.** In `navonline/config/`, copy the example file and fill it in by hand with the technical user's data: login, password, first 8 digits of the tax number, signing key and exchange key.

   ```bash
   cp config/credentials.php.example      config/credentials.php        # test environment
   cp config/credentials.prod.php.example config/credentials.prod.php   # production (only if needed)
   ```

   These files are listed in `.gitignore`. **Never commit them**, and never paste their contents into a chat, ticket or log. On the server, restrict read access to the web server user.

5. **XSD schemas.** Download the NAV OSA 3.0 schemas and set their folder in the module setup (`NAVONLINE_XSD_DIR`).

6. **Enable the modules.** In *Setup → Modules/Applications*, enable **NavOnline**, and **Mentes** if you need it. Enabling NavOnline creates its database tables (from the `sql/` folder) and its scheduled jobs.

7. **Configure the module** (*NavOnline → Setup*): software data (softwareId, name, version, developer), invoice appearance, cash accounting, small business (KATA), XSD folder, alert e-mail addresses, environment (test / production) and dry-run mode. You can also test the connection and the alert e-mail from this page.
   - When a foreign currency invoice is validated, the module sets the MNB rate valid on the date of supply (Áfa tv. 80. §; for an intra-Community supply, the rate of the invoice date). To turn this off, set `NAVONLINE_RATE_AT_TAX_POINT = 0` (*Setup → Other setup*).
   - For a domestic organisation that has no tax number and isn't a VAT subject, tick **Not a VAT subject (no tax number)** on the third party card.

8. **Scheduled jobs** (*Setup → Scheduled jobs*):
   - `NavOnlineSendQueue` processes the send queue every 5 minutes. It is disabled by default.
   - `NavOnlineMnbRates` downloads the daily MNB exchange rates. It requires the `multicurrency` module.
   - `NavOnlineInboundSync` refreshes the daily list of inbound invoices. It only queries NAV.

   The OS scheduler can run these jobs instead of Dolibarr's internal scheduler:

   ```bash
   php htdocs/custom/navonline/scripts/navonline_cron.php
   ```

9. **Exchange rate precision (recommended).** If you issue invoices in foreign currencies, run `navonline/sql/core_multicurrency_tx_precision.sql` by hand. It gives the `multicurrency_tx` columns 12 decimals. After a Dolibarr upgrade, check the columns and run it again if needed.

10. **PDF templates.** Under *Setup → Modules → Invoices / Proposals / Orders …*, select the Hungarian templates (e.g. `sponge_hu`, `cyan_hu`, `espadon_hu`).

11. **Mentes module (optional).** Create a Windows scheduled task that runs your backup script. Its default name is `Dolibarr mentes`; you can set a different name with the `MENTES_TASK_NAME` constant. Then enable the module. At logout, the module starts the task with `schtasks /Run`, so logging out doesn't take longer.

### Bank statement import

*Bank → Bank statement import*: you can upload the bank's monthly statement (camt.053 XML) or the account history (XML). The module recognises the bank account from the file. An entry is never imported twice, even if you import both files.

- The module pairs the entries with the bank lines already recorded in Dolibarr: same amount, within ±10 days, and when it's clear-cut, the partner's bank account number or the invoice number in the remittance text. A monthly statement also marks the paired entries as reconciled with the statement number, and it checks the opening and closing balances.
- For entries that can't be paired (bank charges, card purchases, transfers between your own accounts), the module offers a booking, and you approve each one.

### Tax authority audit export (adóhatósági ellenőrzési adatszolgáltatás)

*Billing → Customer invoices → Adóhatósági ellenőrzési adatszolgáltatás*. This function is required by 23/2014. (VI. 30.) NGM rendelet 11/A. § and keeps that prescribed name. Only administrators can use it.

- Whenever a customer invoice is validated, the module saves its data as issued, as NAV Online Invoice invoiceData 3.0 XML, with an XSD check and a checksum. For a private person customer, the name and address are included, although they are left out of the report sent to NAV. For OSS invoices, the foreign VAT rate is kept. Pro forma invoices aren't saved.
- You can select invoices by issue date, or by series and invoice number range. Both ends are included. Before you download, a preview shows the number of invoices, the totals, the status of each invoice and any gaps in the numbering.
- The ZIP contains one XML file per invoice, the manifests `jegyzek.xml` and `jegyzek.csv`, and a `hibalista.txt` if there is anything to report. If an invoice's data is missing or invalid, or the numbering has a gap, the package is marked "HIANYOS" (incomplete). Every export is logged, and nothing is sent to NAV.
- For invoices validated before this function existed, run `php scripts/navonline_x01_backfill.php --run` to save their data. These saves are marked "not at issue".

### Partner data from NAV

When you create or edit a partner, after you enter a Hungarian tax number the module queries NAV Online Számla (queryTaxpayer, read-only) and fills in the empty fields: name (NAV short name), full name (alias), full tax number, VAT group ID, address, postcode and town.

- If a field is already filled and NAV returns something different, the module doesn't overwrite it: it shows the NAV value next to the field with an "Átvétel" (take over) link.
- If the tax number doesn't exist or NAV reports it as invalid, a message appears under the tax number field.
- Environment: `NAVONLINE_TAXPAYER_ENVIRONMENT` (production by default, independent of invoice reporting); turn it off with `NAVONLINE_TAXPAYER_LOOKUP = 0`. Requires the right to create partners.

### EPR and product fee

*Setup → Modules → NAV Online Számla → EPR / termékdíj*: fee codes (EPR or product fee, KT code, rate, unit, validity). On the *EPR* tab of a product card you switch a code on for the product, with a factor (fee base per unit, e.g. kg per piece).

- When a customer invoice is validated, the module records the fee for each line: fee base = quantity × factor, fee = fee base × the rate valid on the date of supply. This applies only to Hungarian domestic sales (Hungarian seller and customer), on invoices, replacement invoices and credit notes; not on deposit or pro forma invoices.
- A credit note uses the fee data of its original invoice, with a negative fee base. If no rate is valid, the line is left out and the module warns, but the validation goes ahead. A new rate is a new row from its first day, so the fee on earlier invoices doesn't change.
- On the invoice (sponge_hu template), the fee is shown under the line and in a separate block below the totals. It is for information: it's included in the price and doesn't change the total.

### Before going live

- Test sending in the **test environment** first (`https://api-test.onlineszamla.nav.gov.hu/invoiceService/v3`).
- The test environment's `queryTaxpayer` may return wrong VAT codes. Don't copy them into production data.
- Before going live, turn on dry-run, set the go-live date (`NAVONLINE_GOLIVE_DATE`), and only then switch to production.
- When going live, turn on `INVOICE_CAN_NEVER_BE_REMOVED`: an issued invoice can't be deleted, only reversed with a credit note. Delete the test invoices before that.
