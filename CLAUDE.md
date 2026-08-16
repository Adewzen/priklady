# CLAUDE.md

Kontext pro práci na tomhle repozitáři. Viz taky `README.md` (co to je) a hlavně
`DESIGN.md` (jak funguje generátor a proč).

## Než sáhneš do `src/`

**Přečti `DESIGN.md`.** Je tam zdokumentovaná řada bugů, které se do algoritmu
vloudily a byly opravené — typicky ne zjevné z kódu samotného (např. proč `Sub`
zamítá cíl přesně `0`, proč se koše v `Rng::digitBuckets()` slučují, proč se
operátor losuje ve váženém pořadí místo nezávisle při každém pokusu). Bez
přečtení hrozí reálné riziko, že se stejný bug znovu zavede jinou cestou.

## Tech stack a omezení

- PHP 8.3, žádný Composer, žádné závislosti, žádný build krok. Ať to tak zůstane.
- Frontend je čistý PHP + inline CSS/JS v `index.php` — žádný framework, žádný
  bundler. Interaktivita (presety, podmíněně skrytá pole, tooltips) je řešená
  primárně čistým CSS (`:has()`) a minimem vanilla JS.
- Vizuál je jedno pevné tmavé "školní tabule" téma — záměrně bez light/dark
  přepínání (viz commit historie kolem výběru vizuální varianty). Pokud se má
  přidat světlý režim, je to vědomá změna rozhodnutí, ne default.

## Testování

Není tu formální test suite. Při každé změně v `src/` nebo `index.php`:

1. `php -l` na všechny změněné soubory.
2. Ručně ověřit přes `curl -X POST http://localhost/index.php -d "..."` (Apache
   běží lokálně, webroot = kořen repa) — zkontrolovat, že se generuje očekávaný
   počet příkladů a že vypadají rozumně (ne triviální/degenerované vzory).
3. Reprodukovatelnost: stejný seed ve **dvou samostatných PHP procesech** (ne
   dvě instance v jednom skriptu — `mt_srand` je globální stav procesu, viz
   `Rng.php`) musí dát bit-přesně stejný výstup.
4. Okrajové případy: nemožné zadání (např. příliš úzký rozsah) musí selhat
   s hláškou do ~2 s (`GenerationFailedException`), ne nekonečnou smyčkou.
5. Pro UI změny: screenshot přes headless Chromium (`/usr/bin/chromium
   --headless`), případně `puppeteer-core` nainstalované do scratchpad adresáře
   (ne do repa) pro klikací interakce (presety, rozbalování `<details>`).

## Konvence

- Veškerý uživatelsky viditelný text a komentáře v kódu jsou česky. Identifikátory
  (třídy, metody, proměnné) anglicky.
- Desetinná čísla se počítají jako škálovaná celá čísla (`GeneratorConfig::scale()`
  = `10^desetinná místa`), nikdy jako float — kvůli přesnosti a kvůli tomu, aby šlo
  spolehlivě testovat na přesnou rovnost (např. detekce triviálních `a-a` vzorů).
- Backtracking: když se něco nedá sestavit, vyhodí se `RetryExhaustedException`
  a volající to zkusí jinak (jiný operátor, jiný rozpad rozpočtu operací, nakonec
  úplně nový příklad). Nové heuristiky/omezení by měly zapadat do tohohle vzoru,
  ne zavádět vlastní ad-hoc řízení chyb.
- Formulářové presety (ročníky) jsou čistě klientská JS věc — server o nich neví,
  vidí jen výsledné hodnoty polí, přesně jako při ručním vyplnění.
- Commit zprávy česky, jedna logická změna na commit, vždy se spuštěnou regresní
  kontrolou před commitem (viz Testování výše).
