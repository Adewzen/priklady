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

## Heuristika: omezení `a + (-b)` / `a - (-b)`

Se zapnutými zápornými čísly `pickAddOperands`/`pickSubOperands` (v `ExampleGenerator`)
původně vybíraly operandy čistě náhodně, což často vedlo k ošklivým zápisům jako
`40 + (-19)` nebo `40 - (-19)` (a při vnoření do dalších uzlů i k řetězení víc takových
konstrukcí za sebou). Teď se u obou preferuje `b >= 0` (tj. výsledek za `+`/`-` je kladný
list) — posledních několik pokusů z `MAX_NODE_RETRIES` rozsah uvolní na celý povolený
interval, ať se generování nezacyklí, když nezáporné `b` v daném rozsahu není dosažitelné.
Je to jen bias, ne tvrdý zákaz — se zapnutými zápornými čísly se pořád objeví, jen řidčeji.

Síla biasu je nastavitelná ve formuláři (sekce "Pokročilé") jako `GeneratorConfig::
doubleNegativeBiasPercent` (0–100, výchozí 70). `ExampleGenerator::shouldPreferNonNegative()`
si pro každý `+`/`-` uzel s touto pravděpodobností "hodí mincí", jestli se pro něj bias
vůbec zkusí uplatnit — takže % přímo odpovídá zhruba tomu, u jak velkého podílu uzlů
se preference projeví (ne přesně výsledné frekvenci vzoru ve vygenerovaných příkladech,
protože jeden příklad má víc uzlů a stačí, aby vzor vznikl na jediném z nich).
Změřeno na dávce 300 příkladů (rozsah -1000..1000, jen `+`/`-`, 3 operace):

| bias | výskyt vzoru v příkladu |
|---|---|
| 0 %   | 88 % |
| 30 %  | 75 % |
| 70 %  | 55 % |
| 100 % | 29 % |

Ani 100 % bias vzor úplně nevymýtí — pár posledních retry pokusů se vždy rozsah uvolní
na celý povolený interval, ať se generování nezacyklí, když nezáporné `b` v daném rozsahu
není vůbec dosažitelné.

Vedle toho byl opravený bug v `Serializer::printChild()` — otevření nové závorky
zakládá "nový začátek výrazu", takže se vnitřek už neobalí zbytečně druhou vnitřní
závorkou kolem úvodního záporného čísla (`((-19) + 38)` → `(-19 + 38)`).

## Heuristika: omezení opakovaného `× (-1)`

Podobný problém jako výše, ale u násobení/dělení: `-1` je jako jediná výjimka povolené
i přes obecný zákaz "1" jako činitele (viz tabulka rozsahu platnosti pravidel výše) —
jednotlivé `× (-1)` je smysluplná operace (otočí znaménko), ale **dvě `-1` za sebou se
navzájem vyruší** a jde zase jen o skrytou triviální operaci (`249 × (-1) × (-1) = 249`),
přesně to, čemu měl zákaz "1" původně zabránit.

`GeneratorConfig::maxNegativeOneFactors` (výchozí 1, nastavitelné 0–5 ve formuláři v sekci
"Pokročilé") omezuje, kolikrát smí `-1` vystoupit jako činitel (`×`, obě strany) nebo dělitel
(`÷`, jen dělitel — `-1 ÷ 5` není stejný druh trivality jako `a ÷ (-1)`) v rámci JEDNOHO
příkladu. `ExampleGenerator` si drží čítač `$negativeOneFactorsUsed`, resetovaný na začátku
`generateOne()`.

Důležitý detail v `build()`: čítač se musí zvýšit **hned po výběru operandů**, ne až po
úspěšném sestavení celého podstromu — jinak by uzel postavený uvnitř levé/pravé větve
neviděl, že rodičovský uzel `-1` už použil, a mohl by ho použít znovu (přesně tenhle bug
se objevil při prvním pokusu — čítač se tehdy zvyšoval až po `return new BinaryOp(...)`,
čili pozdě). Při selhání potomků (retry na této úrovni) se zvýšení vrací zpět. Zůstává
jedna známá nepřesnost: pokud se celý tenhle uzel později zahodí kvůli selhání sourozence
o úroveň výš ve stromu, čítač už se nevrací — důsledek je jen mírně konzervativnější
chování (občas se `-1` nepoužije, i když by ještě "mohlo"), nikdy chybný výstup.

**Druhý bug, odhalený vlastním testem po přidání bias na počet cifer (viz níže):** u
násobení mohou vyjít OBA operandy uzlu jako `-1` naráz — třeba cíl `1` se dá rozložit
jen jako `(-1) × (-1)` (jediný dělitel čísla 1 je 1 samo). Původní `isNegativeOneFactor()`
vracela jen ano/ne, takže se to počítalo jako "1 použití", i když šlo o 2 najednou.
Nahrazeno `countNegativeOneFactors()`, která vrací 0/1/2, a kontrola kapacity teď hlídá
`aktuální_čítač + tenhle_uzel > limit`, ne jen `aktuální_čítač >= limit`.

## Heuristika: bias na počet cifer

Čistě uniformní losování z `[min, max]` dává u širokých rozsahů silně nerovnoměrné
zastoupení — u `0..1000` vyjde jen ~1 % čísel jednociferných, ~9 % dvouciferných a ~90 %
trojciferných (a pár desetin procenta čtyřciferných). `Rng::intBiasedByDigits()` místo
toho nejdřív rovnoměrně vylosuje "třídu" (kombinaci znaménka a počtu cifer absolutní
hodnoty — kladná/nula `[0,9]`, `[10,99]`, `[100,999]`, ...; záporná `[-9,-1]`, `[-99,-10]`, ...,
každá jako samostatná položka v seznamu) a teprve uvnitř vybrané třídy losuje uniformně.
Volá se všude, kde se vybírá číslo ze SPOJITÉHO rozsahu (`randomValue` pro kořenový cíl,
`pickAddOperands`, `pickSubOperands`, dělitel v `pickDivOperands`) — **ne** v `pickMulOperands`,
kde se vybírá z diskrétní množiny dělitelů cíle, ne ze spojitého intervalu; šlo by přidat
podobným bucketováním kandidátů, zatím to nebylo potřeba.

Není to dokonalé (0 spadá do stejné třídy jako 1-9, hranice tříd nejsou "kulaté" z pohledu
uživatele) a negarantuje přesné rozložení počtu cifer u LISTŮ stromu (bias se aplikuje na
každé rozhodnutí při stavbě stromu, ne na finální listy nezávisle) — ale změřeno na dávce
500 příkladů (2 operace, `+`/`-`, rozsah 0–1000): 28 % jednociferných, 27 % dvouciferných,
38 % trojciferných, 7 % čtyřciferných (počítáno včetně hraničního `1000`). Výrazně
vyrovnanější než čistě uniformní rozdělení.

**Bug nahlášený uživatelem ("počet operací na příklad je rozbité"):** ve skutečnosti
`operationsCount` fungoval správně (počet operátorů odpovídal), ale u kulatých rozsahů
(typicky `min`/`max` přesně na mocnině deseti, např. `-1000..1000`) vznikal poslední
dekádový koš prakticky prázdný — `[1000,1000]`, jediná hodnota — a dostával STEJNOU `1/N`
váhu jako koš s stovkami hodnot. Číslo `1000`/`-1000` se pak losovalo ~12,5 % případů
místo přirozených ~0,05 %, což se s vyšším `operationsCount` (víc příležitostí) projevovalo
jako nápadně častý vzor `1000 - 1000 + ... - 1000 + ...` — vypadalo to jako by generátor
"plýtval" operacemi na nesmyslné rušení, ne jako problém s počtem operací samotným.

Oprava v `Rng::decadeBuckets()`: poslední koš se sloučí s předchozím, pokud je menší než
1/10 jeho velikosti (např. `[1000,1000]` → sloučit do `[100,999]` → `[100,1000]`).
Legitimně velké okrajové koše (např. `[1000,1500]` u rozsahu `-1000..1500`, 501 hodnot)
se NEslučují — jen ty nepřiměřeně malé. Ověřeno testem: výskyt `±1000` z 20 000 losování
klesl z 12,5 % na 0,04 % (i pod čistě uniformních ~0,10 %, což je správně — bias
záměrně upřednostňuje kratší čísla).

Jde vypnout checkboxem "Vyrovnat zastoupení počtu cifer" v sekci "Pokročilé"
(`GeneratorConfig::digitCountBiasEnabled`, výchozí zapnuto) — `ExampleGenerator::
pickRangedInt()` pak přepíná mezi `Rng::intBiasedByDigits()` a obyčejným `Rng::int()`.

## Váhy operátorů

`GeneratorConfig::operatorWeights` (pole `Operator::value => 0-100`, ve formuláři čtyři
pole v sekci "Pokročilé", výchozí 50 pro každý) řídí pravděpodobnost výběru operátoru
v rámci POVOLENÝCH operátorů (váha operátoru, který není v `operators`, se nikdy
nepoužije).

**Bug nalezený po nasazení (hlášeno jako "násobení reaguje na ovládání od dělení"):**
první verze (`pickWeightedOperator()`) losovala operátor NEZÁVISLE znovu z celého
váženého rozdělení při KAŽDÉM pokusu v `build()`. Když zvolený operátor pro daný cíl
selhal, další pokus si znovu mohl vylosovat TÉŽ jeho — a pro operátory, které jsou pro
některé cíle strukturálně neřešitelné (typicky `÷` bez záporných čísel u cíle většího
než polovina maxima — jediné funkční `b` by muselo být `1`, a to je zakázané), se tím
šance nekontrolovaně přesouvaly k operátoru, který se pro daný cíl snáz podaří — bez
ohledu na nastavené váhy. Izolovaný test samotné `pickWeightedOperator()` (bez vlivu
retry) dal správný poměr (83,2 % vs. očekávaných 83,3 %) — bug tedy nebyl v matematice
výběru, ale v tom, že se losovalo ZNOVU při každém neúspěšném pokusu.

**Oprava:** `ExampleGenerator::weightedOperatorOrder()` vylosuje pro daný uzel POŘADÍ
všech povolených operátorů JEDNOU (ruletové losování bez opakování ze zmenšujícího se
zbytku), a `build()` pak zkusí KAŽDÝ operátor v tomhle pořadí s plnou sadou
`MAX_NODE_RETRIES` pokusů (různé rozdělení rozpočtu), než přejde na dalšího v pořadí.
Operátor s váhou 0 se do pořadí vůbec nezařadí (nikdy se nezkusí) — POKUD existuje
aspoň jeden povolený operátor s kladnou váhou; když mají váhu 0 úplně všechny, spadne
se na čistě náhodné pořadí mezi nimi, ať má generování vůbec co zkoušet.

**Zbývající nepřesnost (inherentní, ne bug):** i s touhle opravou platí, že když je
pro konkrétní cíl operátor s vyšší váhou strukturálně neřešitelný (viz výše — typicky
`÷`/`×` v úzkém rozsahu bez záporných čísel), pořadí ho sice vyzkouší jako první, ale
neuspěje, a použije se další v pořadí MÍSTO NĚJ — realizovaný poměr se pak nedokáže
přesně shodovat s nastavenými vahami. To nejde odstranit beze změny základního
algoritmu (cíl se volí NEZÁVISLE na operátoru, teprve pak se hledá funkční rozklad) —
lze to jen zmírnit širším rozsahem a/nebo povolenými zápornými čísly.
Ověřeno testem se dvěma extrémy:
- úzký rozsah `[2,200]` bez záporných čísel, váhy ×90 ÷10 (chceme 83/17): vyšlo 62/38 —
  citelná odchylka.
- široký rozsah `[-1000,1000]` se zápornými čísly, stejné váhy: vyšlo 87/13 — v mezích
  běžného statistického šumu pro vzorek 400 příkladů.

## Známé zjednodušení / TODO na příště

- **Variabilní počet operací**: teď je pevný podle zadání, ne rozsah/náhoda.
- **Vychýlení volby cíle pro `×`/`÷`** směrem k číslům s víc děliteli — zatím čistě
  náhodné, může to znamenat víc retry na úzkých rozsazích.
- **Rozvoj do neasociativních větví** (`-`, `÷`): zatím se rozvíjí levá i pravá větev
  rovnocenně; pokud by to v praxi dělalo problémy, dá se omezit.
- Zlomky, tisk a vizuální design — záměrně mimo scope teď.

## Malá násobilka

Samostatný generátor (`SmallMultiplicationTableGenerator`), ne speciální případ
`ExampleGenerator`. Důvod: hlavní algoritmus používá JEDEN rozsah `[min,max]` jak pro
cílový výsledek, tak pro operandy (viz tabulka rozsahu platnosti pravidel výše) — malá
násobilka ale potřebuje činitele 1–10, zatímco výsledek smí být až 100 (10×10). Zavést
druhý nezávislý rozsah do rekurzivního algoritmu kvůli jedné ploché, jednoduché funkci
by nebylo úměrné — proto samostatná třída, znovupoužívající stejné `Node`/`Operator`/
`Rng`/`Serializer`.

Když je v `index.php` zaškrtnuté "Malá násobilka", přepíše to generování (`Small
MultiplicationTableGenerator` místo `ExampleGenerator`) i `GeneratorConfig` použitou
pro `Serializer` (aby se čísla tiskla jako celá, bez závorek) — všechna ostatní pole
formuláře (operace, rozsah, další jevy, pokročilé) se v tomhle režimu ignorují; platí
jen počet příkladů, seed a zobrazení výsledků, které jsou orthogonální k oběma režimům.

Dělení generuje přesně inverzní fakt k násobení (`a×b=dělenec`, `b`=dělitel, výsledek
`a`) — nejde o obecné dělení s hledáním libovolného dělitele, takže tu neplatí pravidla
"žádná 1" ani limit na `-1` (malá násobilka nemá záporná čísla vůbec, a `×1`/`÷1` jsou
legitimní, byť triviální, násobilkové fakty, které se v tradiční výuce neskrývají).
