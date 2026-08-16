# Příklady

Webová aplikace na generování aritmetických příkladů pro procvičování na základní
škole (1.–5. třída). Zadáš obtížnost (nebo si nastavení sestavíš sám), aplikace
vygeneruje seznam příkladů a volitelně i výsledky — hotové na vytištění.

**Běžící instance:** https://priklady.adewzen.cz

## Funkce

- Presety podle třídy (1.–5.) + volba "Vše" pro plné, ničím neomezené nastavení
- Sčítání, odčítání, násobení, dělení — jednotlivě zapínatelné, s nastavitelnou
  vzájemnou pravděpodobností
- Malá násobilka jako omezení kladené na uzly × a ÷ (činitelé/dělitel 1–10) —
  zbytek příkladu (rozsah, +/−, počet operací, závorky) se generuje normálně
- Závorky a priorita operátorů (volitelně, včetně korektního dozávorkování,
  když je priorita vypnutá)
- Záporná a desetinná čísla, s heuristikami proti "ošklivým" nebo triviálním
  zápisům (viz `DESIGN.md`) a biasem k jednodušším/celým číslům
- Reprodukovatelnost přes seed — stejný seed + stejné zadání = stejné příklady,
  seed jde volitelně zahrnout přímo do vytištěného zadání
- Tisk (přes tiskový dialog prohlížeče — "Uložit jako PDF" jde zvolit tam)
- Pokročilé nastavení pro doladění heuristik generátoru

## Požadavky

- PHP 8.3+ — čisté, bez Composeru a bez dalších rozšíření
- Webserver s podporou PHP (Apache, nebo stačí vestavěný PHP server)

## Spuštění lokálně

```bash
php -S localhost:8000
```

a otevřít `http://localhost:8000`. Nebo nasměrovat webroot Apache/nginx s PHP
na kořen repozitáře — vstupní bod je `index.php`.

## Struktura

- `index.php` — formulář, zpracování requestu a zobrazení vygenerovaných příkladů
- `bootstrap.php` — jednoduchý autoloader pro `src/`
- `src/` — samotný generátor (třídy `ExampleGenerator`, `GeneratorConfig`, `Rng`,
  `Serializer`, `Operator`, `Node`/`Literal`/`BinaryOp`)
- `DESIGN.md` — jak funguje algoritmus generování, historie nalezených bugů
  a heuristik a proč je věci postavené tak, jak jsou — vyplatí se přečíst před
  úpravou čehokoliv v `src/`

## Poznámka

Kód i tenhle README vznikaly s pomocí AI (Claude Code) — `DESIGN.md` je proto
psané dost podrobně, ať zůstane srozumitelné i bez přímé účasti na vývoji.

## Licence / autor

© Jakub Nezveda
