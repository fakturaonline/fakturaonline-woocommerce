# FakturaOnline pro WooCommerce

Plugin propojí váš e-shop na WooCommerce s [FakturaOnline](https://www.fakturaonline.cz). Po dokončení objednávky vystaví fakturu s číslem z vaší číselné řady, zaplacené objednávky označí jako uhrazené a PDF faktury může přiložit k e-mailu pro zákazníka.

## Požadavky

- WordPress 6.4 nebo novější, PHP 8.0 nebo novější
- Aktivní WooCommerce (včetně nového úložiště objednávek HPOS)
- Účet na fakturaonline.cz nebo fakturaonline.sk s aktivním předplatným a API klíčem

## Instalace

1. Stáhněte `fakturaonline-woocommerce.zip` z [Releases](../../releases).
2. Ve WordPressu otevřete **Pluginy → Přidat nový → Nahrát plugin**, vyberte zip a plugin aktivujte.
3. Nastavení najdete v menu **WooCommerce → FakturaOnline**.

## Nastavení

### 1. Připojení

1. Ve FakturaOnline otevřete **Nastavení → API klíče** a vytvořte nový klíč. Začíná `fo_live_` a zobrazí se jen jednou, hned si ho zkopírujte.
2. V pluginu vyberte instanci (**fakturaonline.cz** nebo **fakturaonline.sk**) podle toho, kde máte účet.
3. Vložte klíč a klikněte na **Uložit změny**.
4. Klikněte na **Otestovat spojení**. Test používá uložený klíč, proto nejdřív uložte.

Když test hlásí chybu 401, klíč je neplatný nebo je vybraná špatná instance. Chyba 403 znamená, že předplatné FakturaOnline není aktivní.

### 2. Vystavování faktur

| Volba | Co dělá |
|---|---|
| **Druh dokladu** | *Faktura – daňový doklad* pro plátce DPH, *Faktura* pro neplátce. |
| **Vystavit automaticky při stavu** | *Dokončeno* (výchozí), *Zpracovává se*, nebo *Nevystavovat automaticky*. Ručně jde fakturu vystavit vždy. |
| **Splatnost (dní)** | Počet dní od data vystavení. |
| **PDF v e-mailu** | Přiloží PDF faktury k zákaznickému e-mailu „Objednávka dokončena“. |

### 3. Dodavatel

Údaje vašeho e-shopu, které se tisknou na fakturu. Dokud je neuložíte, plugin nabídne to, co už WooCommerce zná: název webu, adresu obchodu, e-mail odesílatele a bankovní účet z brány „Bankovní převod“. Doplňte **IČO**, **DIČ** (na Slovensku i **IČ DPH**) a telefon a uložte.

Když necháte název prázdný, plugin převezme dodavatele z vaší poslední faktury ve FakturaOnline. Nový účet bez faktur ale dodavatele nemá a plugin fakturu nevystaví, v tom případě ho vyplňte zde.

### 4. Logo a razítko

Vyberte obrázky z knihovny médií (JPG, PNG, GIF). Plugin je do FakturaOnline nahraje při prvním vystavení faktury a dál posílá jen jejich ID. Po změně API klíče nebo instance je nahraje znovu.

## Jak plugin funguje

**Automaticky.** Jakmile objednávka přejde do zvoleného stavu, plugin vystaví fakturu a do poznámek objednávky zapíše její číslo. Faktura se vystaví jen jednou, opakovaná změna stavu ji nevystaví znovu.

**Ručně.** V detailu objednávky je box **FakturaOnline**. Když faktura chybí, je tam tlačítko **Vystavit fakturu**. Když existuje, vidíte její číslo, tlačítko **Stáhnout PDF** a odkaz **Otevřít ve FO**.

**Zaplacené objednávky.** Pokud je objednávka v okamžiku vystavení zaplacená (podle WooCommerce), plugin fakturu ve FakturaOnline hned označí jako uhrazenou.

**PDF v e-mailu.** Se zapnutou volbou se PDF faktury přiloží k e-mailu „Objednávka dokončena“. Faktura se vystavuje ještě před odesláním tohoto e-mailu, takže příloha je v něm od začátku.

**Když se něco nepovede.** Výpadek spojení nebo chyba z API nic nerozbije: do poznámek objednávky se zapíše důvod a fakturu vystavíte ručně tlačítkem. Objednávka se normálně zpracuje dál.

## Co je na faktuře

| Položka | Zdroj |
|---|---|
| Číslo faktury | Vaše číselná řada ve FakturaOnline |
| Datum vystavení a DUZP | Den vystavení (v časové zóně webu) |
| Splatnost | Nastavení pluginu |
| Variabilní symbol | Číslice z čísla objednávky (max. 10) |
| Poznámka | „Objednávka č. …“ |
| Měna | Měna objednávky |
| Odběratel | Fakturační údaje z objednávky: firma nebo jméno, adresa, e-mail, telefon |
| IČO, DIČ, IČ DPH odběratele | Meta klíče `_billing_ico`, `_billing_dic`, `_billing_ic_dph` (viz níže) |
| Položky | Název a SKU, množství, jednotková cena, sazba DPH |
| Doprava a poplatky | Samostatné řádky, pokud nejsou nulové |
| Způsob úhrady | Podle platební brány (viz níže) |

**Ceny a DPH.** Plugin respektuje nastavení WooCommerce, zda zadáváte ceny s DPH nebo bez. Sazba DPH každého řádku se bere z daňové sazby, kterou WooCommerce na položku uplatnilo. Slevy z kupónů se promítnou do jednotkové ceny řádku (až 4 desetinná místa) a do názvu položky, např. „Tričko (TS-1) (sleva 10 %)“. FakturaOnline pak počítá řádky stejně jako WooCommerce, takže součet faktury sedí s objednávkou na haléř.

**Způsob úhrady.** `bacs` → bankovní převod, `cod` → dobírka, `cheque` → hotově, `paypal` → PayPal, ostatní brány (karty, Stripe, GoPay, Comgate…) → platební karta.

## Přizpůsobení pro vývojáře

### IČO a DIČ z jiného pokladního pluginu

Plugin čte IČO, DIČ a IČ DPH z meta klíčů `_billing_ico`, `_billing_dic` a `_billing_ic_dph`. Používá-li váš pokladní plugin jiné klíče, přepište je filtrem v `functions.php` vašeho tématu nebo ve vlastním pluginu:

```php
add_filter( 'fakturaonline_invoice_payload', function ( array $payload, WC_Order $order ) {
	$payload['buyer_attributes']['company_number'] = $order->get_meta( '_billing_company_id' );
	$payload['buyer_attributes']['tax_number']     = $order->get_meta( '_billing_vat_id' );
	return $payload;
}, 10, 2 );
```

Stejným filtrem upravíte cokoli v datech faktury těsně před odesláním, například poznámku nebo popisy řádků.

### Jiná platební brána

```php
add_filter( 'fakturaonline_means_of_payment', function ( string $value, string $gateway_id ) {
	return $gateway_id === 'moje_brana' ? 'bank_transfer' : $value;
}, 10, 2 );
```

Hodnoty odpovídají API FakturaOnline: `bank_transfer`, `cash`, `cash_on_delivery`, `credit_card`, `paypal`.

## Řešení problémů

| Problém | Co s tím |
|---|---|
| Faktura se nevystavila | Otevřete detail objednávky a v poznámkách najděte řádek „FakturaOnline: fakturu se nepodařilo vystavit“ s důvodem. Po opravě klikněte na **Vystavit fakturu**. |
| „Neplatný API klíč nebo špatná instance“ | Zkontrolujte, že klíč patří k účtu na zvolené instanci (cz vs. sk). |
| „Předplatné FakturaOnline není aktivní“ | Obnovte předplatné ve FakturaOnline. |
| „Chybí dodavatel“ | Vyplňte sekci Dodavatel v nastavení pluginu. |
| E-mail odešel bez PDF | Faktura se nepodařila vystavit nebo se nepodařilo stáhnout PDF. Důvod je v poznámkách objednávky. |

## Omezení

- Podporuje fakturaonline.cz a fakturaonline.sk.
- Faktura se vystaví jednou. Pozdější změny objednávky se do ní nepropisují, upravte ji ve FakturaOnline.
- Refundace nevytváří dobropis.
- Stav platby se z FakturaOnline zpět do WooCommerce nepropisuje.
- Faktura se vystavuje během změny stavu objednávky, při pomalé odezvě API může uložení objednávky trvat o několik sekund déle.

## Odinstalace

Při smazání pluginu ve WordPressu se odstraní jeho nastavení včetně API klíče. Vystavené faktury ve FakturaOnline a čísla faktur u objednávek zůstávají.

## Vývoj

```bash
php -d zend.assertions=1 -d assert.exception=1 tests/test-invoice-builder.php
bin/build-zip   # vytvoří fakturaonline-woocommerce.zip z posledního commitu
dev/setup.sh    # lokální WordPress + WooCommerce + plugin v Dockeru (http://localhost:8088, admin/admin)
```

Konstantou `FO_BASE_URL` ve `wp-config.php` lze plugin nasměrovat na jinou instanci, `FO_SSL_VERIFY = false` vypne ověřování certifikátu.

### Vydání nové verze

Zvedněte `Version` v hlavičce `fakturaonline-woocommerce.php` a konstantu `FO_WC_VERSION`, commitněte a pushněte tag. GitHub Actions sestaví zip a vytvoří release:

```bash
git tag v0.1.0
git push origin main v0.1.0
```

## Licence

GPLv2 nebo novější, viz [LICENSE](LICENSE).
