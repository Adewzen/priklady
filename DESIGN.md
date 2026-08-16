# Algoritmus generování — jak to funguje a jak to ladit

## Základní myšlenka

Strom výrazu se staví **od výsledku dolů**, ne od operandů nahoru:

1. Zvol náhodnou cílovou hodnotu (v mezích min/max).
2. Zvol operátor a najdi dvojici operandů `a`, `b`, pro kterou `a OP b = cíl`
   (zpětný výpočet — u `+`/`-` je to triviální, u `×`/`÷` je to hledání dělitelů).
3. Rozděl zbývající "rozpočet operací" náhodně mezi levou a pravou větev a rekurzivně
   opakuj na `a` a `b`.
4. Když rozpočet větve dojde na 0, stane se z ní list (konkrétní číslo v zadání).

Datová struktura: `Node` (`src/Node.php`) je rozhraní, `Literal` je list, `BinaryOp`
je vnitřní uzel (operátor + levý/pravý potomek). Každý uzel umí spočítat svou hodnotu
(`scaledValue()`), takže rodič vždy zná přesnou hodnotu, kterou musí jeho operandy dát.

## Rozpočet operací (`src/ExampleGenerator.php::build()`)

Pole "počet operací" ve formuláři = přesně tolik uzlů `BinaryOp` bude mít výsledný strom
(zatím pevné číslo, ne rozsah — viz TODO níže). Rozdělení rozpočtu mezi větve je náhodné
(`leftBudget = rng(0, remaining)`), takže stromy nejsou vždy stejně "vyvážené" — někdy
vznikne dlouhý levý řetězec (`a+b+c+d`), jindy vyváženější strom (`(a+b)×(c-d)`).

## Škálovaná celá čísla místo floatů

Desetinná čísla se interně nepočítají jako `float` (zaokrouhlovací chyby), ale jako
**celá čísla vynásobená `10^desetinná_místa`** (`GeneratorConfig::scale()`). Např. při
1 desetinném místě je `1,7` uvnitř reprezentováno jako `17`. Sčítání/odčítání funguje
beze změny, násobení/dělení musí kompenzovat škálu navíc/chybějící — viz komentáře
v `BinaryOp.php`. Když je `allowDecimals = false`, `scale() === 1` a všechno se chová
jako obyčejná celočíselná aritmetika — to samé.

`BinaryOp` si po každém výpočtu ověří, že dělení vyšlo přesně (`exactDiv()`); pokud ne,
je to bug v `pickOperands`, ne běžný stav pro backtracking — vyhodí `\LogicException`
(ne `RetryExhaustedException`), ať se to nepromění v tichý špatný výsledek.

U násobení/dělení navíc platí: **nejvýš jeden ze dvou operandů smí nést desetinná
místa** (ten druhý musí být celé číslo v daném uzlu). Bez tohoto omezení by přesnost
při řetězení víc násobení rostla nekontrolovaně (0,3 × 0,7 = 0,21 → o řád víc míst).

## Hledání operandů pro `×` a `÷`

Na rozdíl od `+`/`-` (kde libovolné `a` v rozsahu dá platné `b`), u násobení musí `a`
dělit cílovou hodnotu beze zbytku. `pickMulOperands()` proto:
1. Vezme cíl (přeškálovaný), najde všechny jeho kladné dělitele (`divisorsOf()`,
   O(√n) — pro rozsahy typické pro ZŠ je to řádově desítky až stovky iterací).
2. Přidá k nim záporné varianty (pokud jsou záporná čísla povolená).
3. Zamíchá pole vlastním seedovaným Fisher–Yates (`Rng::shuffled()` — **ne** vestavěné
   `shuffle()`, které v PHP 8.2+ nejde spolehlivě seedovat přes `mt_srand`).
4. Projde kandidáty a vrátí první, který projde všemi omezeními (rozsah, "žádná 1",
   "žádná 0 pokud je to list", omezení na desetinná místa).

`÷` je jednodušší — zvolí se dělitel `b` (rejection sampling jako u `+`/`-`), dopočítá
se dělenec `a = cíl × b`.

**Bez vychýlení volby cílového čísla u `×`/`÷`** (v1): necíluje se záměrně na čísla
s hodně děliteli. Pokud bude v praxi moc retry/timeoutů právě u násobení/dělení
s úzkým rozsahem, tohle je první místo, kam přidat vylepšení.

## Retry a backtracking

Tři úrovně, přesně podle zadání:

1. **Uzel** (`build()`): až `MAX_NODE_RETRIES = 10` pokusů o jiný operátor / jiné
   rozdělení rozpočtu / jiné operandy. Pokud selže i rekurzivní sestavení potomků,
   počítá se to jako další pokus na této úrovni (chyba z potomka bublá jako
   `RetryExhaustedException` a spotřebuje jeden z 10 pokusů rodiče).
2. **Celý příklad** (`generateOne()` → `generateBatch()`): pokud vyčerpá kořenový uzel
   všech 10 pokusů, `generateBatch()` to chytí a zkusí úplně nový příklad (nová
   náhodná cílová hodnota).
3. **Celá dávka**: `MAX_BATCH_SECONDS = 2.0` — pokud se do 2 sekund nepodaří vygenerovat
   požadovaný počet příkladů, vyhodí se `GenerationFailedException` a uživatel dostane
   chybovou hlášku (žádný částečný výsledek se nezobrazuje).

Konstanty jsou zatím natvrdo v `ExampleGenerator`. Až bude potřeba (podle zadání zmíněný
"effort" parametr), stačí je udělat konfigurovatelné.

## Priorita operátorů a závorky

Jedno pravidlo (`Serializer::requiresParens()`) řeší obě zapnutí/vypnutí:

- **Priorita zapnutá**: standardní minimální závorkování (jen když je matematicky nutné).
- **Priorita vypnutá**: kdykoliv se u sebe potkají dvě různé třídy priority (+/− vs. ×/÷),
  závorka se přidá vždy, i kdyby nebyla nutná — žák nemusí prioritu znát.
- V obou režimech: u neasociativních operátorů (`-`, `÷`) se závorkuje pravý operand,
  pokud má stejnou třídu priority jako rodič (jinak by se změnil význam výrazu).

Když jsou **závorky zakázané**, `generateOne()` po sestavení stromu zkontroluje
`needsParenthesesSomewhere()` — pokud by strom závorky potřeboval (ať už kvůli prioritě,
nebo kvůli asociativitě `-`/`÷`), celý příklad se zahodí a zkusí se nový (přes stejný
retry mechanismus jako výše). Tahle kontrola funguje stejně bez ohledu na to, jestli je
priorita zapnutá nebo ne.

Když jsou vypnuté **obě** (priorita i závorky) a zároveň jsou povolené operátory z obou
tříd priority, `index.php` zobrazí uživateli varování předem (`usesMultiplePrecedenceClasses()`),
protože takové zadání bude s velkou pravděpodobností často padat na časový limit.

Záporná čísla se navíc vždy zabalí do závorek, pokud nejsou na úplném začátku výrazu
(`5 - (-3)`, ne `5 - -3`) — česká konvence.

## Rozsah platnosti omezení (shrnutí, protože se to snadno plete)

| Pravidlo | List (vypsané číslo) | Mezivýsledek |
|---|---|---|
| min/max rozsah | vždy | vždy |
| záporná čísla (pokud vypnuto) | zakázáno | zakázáno |
| nula | zakázána | povolena |
| "1" jako operand `×`/`÷` (ne výsledek) | zakázáno | zakázáno |
| dělitel `÷` roven 0 | zakázáno vždy (matematická nutnost, ne jen pedagogická volba) | — |

## Seed / reprodukovatelnost

`Rng` obaluje `mt_srand()`/`mt_rand()`. **Pozor**: `mt_srand` nastavuje globální stav
generátoru v celém PHP procesu — víc instancí `Rng` v jednom běhu sdílí stav (druhé
`new Rng($seed)` prostě znovu nasadí seed, ale pokud se mezi tím již čerpalo z generátoru,
sekvence se posune). V praxi to nevadí, protože `index.php` vytváří přesně jeden `Rng`
na request a hned ho použije — ověřeno testem (dva samostatné PHP procesy se stejným
seedem dají bit-přesně stejný výstup). Reprodukovatelnost je garantovaná jen v rámci
téhle verze algoritmu — jakákoliv budoucí změna pořadí volání `rng->int()` naruší staré
seedy (dá to jiný, ale pořád platný výsledek, ne chybu).

## Známé zjednodušení / TODO na příště

- **Variabilní počet operací**: teď je pevný podle zadání, ne rozsah/náhoda.
- **Vychýlení volby cíle pro `×`/`÷`** směrem k číslům s víc děliteli — zatím čistě
  náhodné, může to znamenat víc retry na úzkých rozsazích.
- **Rozvoj do neasociativních větví** (`-`, `÷`): zatím se rozvíjí levá i pravá větev
  rovnocenně; pokud by to v praxi dělalo problémy, dá se omezit.
- Zlomky, tisk a vizuální design — záměrně mimo scope teď.
