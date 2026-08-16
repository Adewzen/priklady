<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Priklady\Operator;
use Priklady\GeneratorConfig;
use Priklady\Rng;
use Priklady\ExampleGenerator;
use Priklady\Serializer;
use Priklady\GenerationFailedException;

function readInt(array $src, string $key, int $default): int
{
    return isset($src[$key]) && $src[$key] !== '' ? (int) $src[$key] : $default;
}

function readFloat(array $src, string $key, float $default): float
{
    return isset($src[$key]) && $src[$key] !== '' ? (float) str_replace(',', '.', (string) $src[$key]) : $default;
}

function readBool(array $src, string $key): bool
{
    return isset($src[$key]);
}

$submitted = $_SERVER['REQUEST_METHOD'] === 'POST';
$input = $submitted ? $_POST : [];

$operatorMap = ['add' => Operator::Add, 'sub' => Operator::Sub, 'mul' => Operator::Mul, 'div' => Operator::Div];

// Preset ročníku nemá na serveru žádný funkční význam pro GENEROVÁNÍ — je to čistě
// klientská (JS) pomůcka, která při výběru přednastaví skutečná pole níž (operators[],
// min, max, ...). Na čerstvém (needeslaném) načtení stránky ale musí nějaká pole mít
// nějakou počáteční hodnotu — místo obecných výchozích hodnot použijeme rovnou "1.
// třídu" (GRADE_PRESETS['1'] v JS níž), ať radio i pole pod ním od začátku souhlasí,
// a nespoléháme na to, že si uživatel preset vybere sám (nebo že to prohlížeč "uhodne"
// při obnovení stránky — proto i autocomplete="off" na formuláři).
$freshLoadDefaults = $submitted ? null : [
    'operationsCount' => 1,
    'min' => 0.0,
    'max' => 20.0,
    'operators' => ['add', 'sub'],
    'negative' => false,
    'decimals' => false,
    'parentheses' => false,
    'priority' => false,
    'smt' => false,
];
$gradePreset = $submitted ? ($input['grade_preset'] ?? '') : '1';

$count = max(1, min(100, readInt($input, 'count', 10)));
$operationsCount = max(1, min(8, readInt($input, 'operations_count', $freshLoadDefaults['operationsCount'] ?? 3)));
$min = readFloat($input, 'min', $freshLoadDefaults['min'] ?? -1000);
$max = readFloat($input, 'max', $freshLoadDefaults['max'] ?? 1000);
if ($min > $max) {
    [$min, $max] = [$max, $min];
}

$selectedOperatorKeys = $submitted ? ($input['operators'] ?? []) : $freshLoadDefaults['operators'];
$operators = [];
foreach ($selectedOperatorKeys as $key) {
    if (isset($operatorMap[$key])) {
        $operators[] = $operatorMap[$key];
    }
}
if ($operators === []) {
    $operators = [Operator::Add, Operator::Sub];
}

$allowNegative = $submitted ? readBool($input, 'allow_negative') : $freshLoadDefaults['negative'];
$allowDecimals = $submitted ? readBool($input, 'allow_decimals') : $freshLoadDefaults['decimals'];
$decimalPlaces = max(1, min(2, readInt($input, 'decimal_places', 2)));
$allowParentheses = $submitted ? readBool($input, 'allow_parentheses') : $freshLoadDefaults['parentheses'];
$allowOperatorPriority = $submitted ? readBool($input, 'allow_priority') : $freshLoadDefaults['priority'];
$showResults = readBool($input, 'show_results');
$useSeed = readBool($input, 'use_seed');
$seed = $useSeed
    ? readInt($input, 'seed', random_int(1, 1_000_000))
    : random_int(1, 1_000_000);
$includeSeedInAssignment = $submitted ? readBool($input, 'include_seed_info') : true;
$doubleNegativeBiasPercent = max(0, min(100, readInt($input, 'double_negative_bias', 70)));
$maxNegativeOneFactors = max(0, min(5, readInt($input, 'max_negative_one_factors', 1)));
$digitCountBiasEnabled = $submitted ? readBool($input, 'digit_count_bias') : true;
$wholeNumberBiasPercent = max(0, min(100, readInt($input, 'whole_number_bias', 70)));

$operatorWeightKeys = ['add' => 'weight_add', 'sub' => 'weight_sub', 'mul' => 'weight_mul', 'div' => 'weight_div'];
$operatorWeights = [];
foreach ($operatorWeightKeys as $opValue => $fieldName) {
    $operatorWeights[$opValue] = max(0, min(100, readInt($input, $fieldName, 50)));
}

$smallMultiplicationTable = $submitted ? readBool($input, 'small_multiplication_table') : $freshLoadDefaults['smt'];

$config = new GeneratorConfig(
    count: $count,
    operationsCount: $operationsCount,
    min: $min,
    max: $max,
    operators: $operators,
    allowDecimals: $allowDecimals,
    decimalPlaces: $allowDecimals ? $decimalPlaces : 0,
    allowNegative: $allowNegative,
    allowParentheses: $allowParentheses,
    allowOperatorPriority: $allowOperatorPriority,
    showResults: $showResults,
    seed: $seed,
    doubleNegativeBiasPercent: $doubleNegativeBiasPercent,
    maxNegativeOneFactors: $maxNegativeOneFactors,
    digitCountBiasEnabled: $digitCountBiasEnabled,
    operatorWeights: $operatorWeights,
    smallMultiplicationTable: $smallMultiplicationTable,
    wholeNumberBiasPercent: $wholeNumberBiasPercent,
);

$priorityParensWarning = $submitted
    && !$allowOperatorPriority
    && !$allowParentheses
    && $config->usesMultiplePrecedenceClasses();

$serializer = new Serializer($config);
$examples = [];
$errorMessage = null;

function formatConfigSummary(GeneratorConfig $config, int $seed): string
{
    $parts = [
        'Seed: ' . $seed,
        'Počet příkladů: ' . $config->count,
        'Operace na příklad: ' . $config->operationsCount,
        'Rozsah: ' . $config->min . ' – ' . $config->max,
        'Operátory: ' . implode(', ', array_map(fn($op) => $op->symbol(), $config->operators)),
        'Záporná čísla: ' . ($config->allowNegative ? 'ano' : 'ne'),
        'Desetinná čísla: ' . ($config->allowDecimals ? "ano ({$config->decimalPlaces} des. místa)" : 'ne'),
        'Závorky: ' . ($config->allowParentheses ? 'ano' : 'ne'),
        'Priorita operátorů: ' . ($config->allowOperatorPriority ? 'ano' : 'ne'),
    ];
    if ($config->allowNegative) {
        $parts[] = 'Bias proti dvojitým znaménkům: ' . $config->doubleNegativeBiasPercent . ' %';
        $parts[] = 'Max. počet "-1" jako činitele: ' . $config->maxNegativeOneFactors;
    }
    $parts[] = 'Bias na počet cifer: ' . ($config->digitCountBiasEnabled ? 'ano' : 'ne');
    if ($config->allowDecimals) {
        $parts[] = 'Bias k celým číslům: ' . $config->wholeNumberBiasPercent . ' %';
    }
    if (count($config->operators) > 1) {
        $weights = [];
        foreach ($config->operators as $op) {
            $weights[] = $op->symbol() . ':' . $config->operatorWeight($op) . ' %';
        }
        $parts[] = 'Váhy operátorů: ' . implode(', ', $weights);
    }
    if ($config->smallMultiplicationTable) {
        $parts[] = 'Malá násobilka (× a ÷ jen 1–10): ano';
    }
    return implode(' · ', $parts);
}

if ($submitted) {
    try {
        $generator = new ExampleGenerator($config, new Rng($seed));
        $examples = $generator->generateBatch();
    } catch (GenerationFailedException $e) {
        $errorMessage = $e->getMessage();
    }
}

?>
<!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Příklady na procvičování</title>
<style>
  :root {
    --board: #1c362e;
    --board-2: #234238;
    --board-3: #2a4d41;
    --chalk: #f1ede4;
    --chalk-dim: #b9c4bc;
    --chalk-faint: #7d8f86;
    --yellow: #e8c468;
    --coral: #dd8a71;
    --coral-strong: #e8785a;
    --line: rgba(241, 237, 228, 0.16);
    --paper: #faf8f2;
    --paper-ink: #262b26;
    --paper-ink-soft: #6b7269;
    --paper-line: #d9d2bd;
    --tape: #e8c468;
    --error-bg: #3a2422;
    --error-border: #a8503e;
    --error-ink: #f3c3b6;
    --warn-bg: #3a3320;
    --warn-border: #b3934a;
    --warn-ink: #f0d9a0;
  }

  * { box-sizing: border-box; }
  html { color-scheme: dark; }
  body {
    margin: 0;
    background: var(--board);
    color: var(--chalk);
    font-family: -apple-system, "Segoe UI", system-ui, sans-serif;
    -webkit-font-smoothing: antialiased;
  }
  body::before {
    content: "";
    position: fixed;
    inset: 0;
    pointer-events: none;
    opacity: 0.5;
    mix-blend-mode: overlay;
    background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='140' height='140'><filter id='n'><feTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='2' stitchTiles='stitch'/><feColorMatrix type='saturate' values='0'/></filter><rect width='100%25' height='100%25' filter='url(%23n)' opacity='0.35'/></svg>");
    background-size: 140px 140px;
  }

  .shell { max-width: 1220px; margin: 0 auto; padding: 2.25rem 1.5rem 4rem; position: relative; }

  header.top { margin-bottom: 1.9rem; }
  header.top h1 { font-size: 2rem; font-weight: 800; letter-spacing: 0.01em; margin: 0 0 0.2rem; text-wrap: balance; }
  header.top .chalk-underline { display: inline-block; width: 6.2rem; height: 5px; background: var(--coral); border-radius: 3px; margin-bottom: 0.7rem; opacity: 0.85; }
  header.top p { margin: 0; color: var(--chalk-dim); font-size: 0.9rem; }

  .layout { display: grid; grid-template-columns: 340px 1fr; gap: 2.25rem; align-items: start; }
  @media (max-width: 900px) { .layout { grid-template-columns: 1fr; } }

  .panel { background: var(--board-2); border: 1px solid var(--line); border-radius: 10px; padding: 1.5rem 1.5rem 1.4rem; }

  .field-group { margin-bottom: 1.4rem; }
  .field-group:last-child { margin-bottom: 0; }
  label.field-label, legend.field-label {
    display: block;
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.09em;
    color: var(--chalk-faint);
    margin-bottom: 0.55rem;
    padding: 0;
    border: none;
  }

  fieldset.field-group { border: none; margin-left: 0; margin-right: 0; padding: 0; margin-bottom: 1.4rem; }

  input[type="number"] {
    width: 100%;
    font: inherit;
    font-size: 1rem;
    padding: 0.55rem 0.7rem;
    border: 1px solid var(--line);
    border-radius: 5px;
    background: var(--board);
    color: var(--chalk);
  }
  input[type="number"]:focus-visible { outline: 2px solid var(--coral); outline-offset: 1px; }
  /* Skrýt nativní šipky nahoru/dolů — v užších polích (např. váhy operátorů vedle sebe
     ve čtyřech sloupcích) braly místo a hodnota se s nimi vůbec nevešla vedle "%". */
  input[type="number"]::-webkit-outer-spin-button,
  input[type="number"]::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
  input[type="number"] { appearance: textfield; -moz-appearance: textfield; }

  .row { display: flex; gap: 0.6rem; }
  .row .field-group { flex: 1; margin-bottom: 0; }

  /* 2×2 mřížka místo 4 polí v řadě — ve 4 sloupcích vedle sebe na šířku panelu
     nebylo dost místa ani na "50 %" (hodnota se ořezávala). */
  .weight-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.6rem 0.7rem; margin-top: 0.4rem; }
  .weight-grid .field-group { margin-bottom: 0; }

  /* --- operator chips --- */
  .op-row { display: flex; gap: 0.5rem; }
  .op-chip {
    position: relative;
    flex: 1;
    text-align: center;
    padding: 0.55rem 0;
    border: 1px dashed var(--line);
    border-radius: 6px;
    font-size: 1.1rem;
    color: var(--chalk-dim);
    cursor: pointer;
  }
  .op-chip input { position: absolute; inset: 0; opacity: 0; margin: 0; cursor: pointer; }
  .op-chip:has(input:checked) { border-style: solid; border-color: var(--coral); color: var(--coral); font-weight: 700; }
  .op-chip:has(input:focus-visible) { outline: 2px solid var(--coral); outline-offset: 1px; }

  /* --- grade preset picker --- */
  .grade-list { display: flex; flex-direction: column; gap: 0.15rem; }
  .grade-row {
    position: relative;
    display: flex;
    align-items: baseline;
    gap: 0.65rem;
    padding: 0.5rem 0.4rem;
    border-radius: 5px;
    cursor: pointer;
    border-left: 3px solid transparent;
  }
  .grade-row input { position: absolute; inset: 0; opacity: 0; margin: 0; cursor: pointer; }
  .grade-row .num { font-weight: 800; font-size: 0.92rem; color: var(--chalk-faint); min-width: 4.6em; }
  .grade-row .desc { font-size: 0.8rem; color: var(--chalk-faint); }
  .grade-row:has(input:checked) { border-left-color: var(--yellow); background: rgba(232, 196, 104, 0.08); }
  .grade-row:has(input:checked) .num { color: var(--yellow); }
  .grade-row:has(input:checked) .desc { color: var(--chalk); }
  .grade-row:has(input:focus-visible) { outline: 2px solid var(--yellow); outline-offset: 1px; }

  /* --- simple toggle rows --- */
  .toggle-row { display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; color: var(--chalk-dim); margin: 0.35rem 0; }
  .toggle-row input[type="checkbox"] { accent-color: var(--coral); }
  .hint { color: var(--chalk-faint); font-size: 0.76rem; margin: 0.4rem 0 0; line-height: 1.4; }
  .contents-label { display: contents; }

  /* Malá "?" bublina s vysvětlením při najetí myší nebo focusu (klávesnicí). */
  .info-tip {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.15rem;
    height: 1.15rem;
    border-radius: 50%;
    background: var(--line);
    color: var(--chalk-dim);
    font-size: 0.68rem;
    font-weight: 700;
    cursor: help;
  }
  .info-tip-popup {
    display: none;
    position: absolute;
    left: 0;
    top: 1.5rem;
    z-index: 20;
    width: 230px;
    padding: 0.65rem 0.75rem;
    background: var(--board);
    border: 1px solid var(--line);
    border-radius: 6px;
    color: var(--chalk);
    font-size: 0.75rem;
    font-weight: 400;
    line-height: 1.45;
    box-shadow: 0 10px 24px rgba(0, 0, 0, 0.45);
  }
  .info-tip:hover .info-tip-popup,
  .info-tip:focus .info-tip-popup,
  .info-tip:focus-visible .info-tip-popup { display: block; }

  /* Pole na seed se ukáže, jen když je zaškrtnuté "Zadat seed" — čistě CSS, bez JS. */
  .seed-group .seed-input { display: none; margin-top: 0.5rem; }
  .seed-group:has(input[name="use_seed"]:checked) .seed-input { display: block; }

  /* Počet desetinných míst se ukáže, jen když jsou zaškrtnutá desetinná čísla. */
  .decimals-group .decimal-places-input { display: none; margin-top: 0.6rem; }
  .decimals-group:has(input[name="allow_decimals"]:checked) .decimal-places-input { display: block; }

  /* Bias k celým číslům dává smysl jen s povolenými desetinnými čísly — checkbox je
     v jiné části formuláře, takže se to musí hlídat přes celý form. */
  .whole-number-bias-group { display: none; }
  form:has(input[name="allow_decimals"]:checked) .whole-number-bias-group { display: block; }

  /* Vstup s jednotkou "%" vedle pole — "%" jako vlastní flex položka (ne přes
     position:absolute s pevně rezervovaným místem), ať v úzkých sloupcích (např.
     4 vedle sebe u vah operátorů) hodnota vždycky dostane, kolik místa potřebuje,
     a "%" si vezme jen tolik, kolik zabere samo. */
  .unit-field { display: flex; align-items: center; gap: 0.35rem; }
  .unit-field input[type="number"] { flex: 1; min-width: 0; }
  .unit-field .unit { flex: none; color: var(--chalk-faint); font-size: 0.8rem; white-space: nowrap; }

  details.advanced { margin-top: 1.6rem; border-top: 1px dashed var(--line); padding-top: 1rem; }
  details.advanced summary { cursor: pointer; font-size: 0.83rem; color: var(--coral); font-weight: 700; list-style: none; }
  details.advanced summary::-webkit-details-marker { display: none; }
  details.advanced summary::before { content: "▸ "; }
  details.advanced[open] summary::before { content: "▾ "; }
  .advanced-body { padding-top: 1.1rem; }
  .advanced-body h4 {
    font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.08em;
    color: var(--chalk-faint); margin: 1.3rem 0 0.7rem;
  }
  .advanced-body h4:first-child { margin-top: 0; }

  details.advanced.nested {
    margin-top: 1.3rem;
    padding: 0.9rem 1rem;
    background: rgba(0, 0, 0, 0.14);
    border-radius: 6px;
    border-top: none;
  }
  details.advanced.nested[open] { padding-bottom: 0.2rem; }
  details.advanced.nested .advanced-body { padding-top: 1rem; }

  button.generate {
    margin-top: 1.7rem;
    width: 100%;
    padding: 0.8rem;
    background: var(--coral-strong);
    color: #241310;
    border: none;
    border-radius: 6px;
    font-size: 0.95rem;
    font-weight: 800;
    cursor: pointer;
  }
  button.generate:hover { filter: brightness(1.08); }

  /* --- worksheet (results) --- */
  .worksheet-wrap { position: relative; padding-top: 0.6rem; }
  .worksheet {
    position: relative; /* bez tohohle by se .tape (position:absolute) vykreslila
      NAD papírem bez ohledu na pořadí v HTML — pozicované prvky se vždy malují
      nad nepozicovanými. Takhle o pořadí rozhoduje pořadí v HTML (papír je za
      páskou v kódu, takže teď správně překryje její spodní část). */
    background: var(--paper);
    color: var(--paper-ink);
    border-radius: 3px;
    box-shadow: 0 18px 40px -18px rgba(0, 0, 0, 0.6), 0 2px 6px rgba(0, 0, 0, 0.25);
    padding: 1.6rem 1.9rem 1.9rem;
  }
  .tape {
    position: absolute;
    top: -0.7rem;
    left: 2.4rem;
    width: 5rem;
    height: 1.6rem;
    background: var(--tape);
    opacity: 0.75;
    transform: rotate(-3deg);
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
  }
  .worksheet .head { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 1rem; flex-wrap: wrap; gap: 0.5rem; }
  .worksheet h2 { font-family: ui-serif, Georgia, serif; font-size: 1.25rem; margin: 0; }
  .print-btn {
    font-size: 0.78rem; color: var(--paper-ink-soft);
    border: 1px solid var(--paper-line); border-radius: 5px;
    padding: 0.32rem 0.65rem; cursor: pointer; background: transparent;
  }
  .print-btn:hover { background: rgba(0, 0, 0, 0.04); }

  .placeholder-text { color: var(--paper-ink-soft); font-size: 0.95rem; }

  /* Jeden sloupec, "visící" odsazení: číslo zůstane vlevo, zalomený pokračovací
     řádek se zarovná pod text (ne pod číslo) — funguje spolehlivě bez ohledu na
     délku výrazu. Dvousloupcové zobrazení jsme zkoušeli, ale u delších příkladů
     (víc operací, desetinná čísla) se zalamovalo nečitelně. */
  ol.examples {
    list-style: none;
    counter-reset: ex;
    margin: 0;
    padding: 0;
    font-family: ui-monospace, "SF Mono", Menlo, monospace;
    font-variant-numeric: tabular-nums;
    font-size: 1.1rem;
  }
  ol.examples li {
    counter-increment: ex;
    line-height: 1.7;
    padding: 0.2rem 0 0.2rem 2.3em;
    text-indent: -2.3em;
  }
  ol.examples li::before { content: counter(ex) "."; color: var(--paper-ink-soft); font-size: 0.92rem; margin-right: 0.5em; }

  .results-block {
    margin-top: 1.2rem;
    padding-top: 0.9rem;
    border-top: 1px solid var(--paper-line);
    font-size: 0.84rem;
    color: var(--paper-ink-soft);
    font-family: ui-monospace, "SF Mono", Menlo, monospace;
  }
  .results-block strong { color: var(--paper-ink); font-family: -apple-system, "Segoe UI", system-ui, sans-serif; }
  .seed-info { margin-top: 0.55rem; font-size: 0.72rem; color: var(--paper-ink-soft); opacity: 0.8; }

  .error, .warning {
    border-radius: 8px;
    padding: 0.85rem 1.1rem;
    margin-bottom: 1.25rem;
    font-size: 0.88rem;
    line-height: 1.5;
  }
  .error { background: var(--error-bg); border: 1px solid var(--error-border); color: var(--error-ink); }
  .warning { background: var(--warn-bg); border: 1px solid var(--warn-border); color: var(--warn-ink); }

  footer.bottom { margin-top: 2.5rem; font-size: 0.78rem; color: var(--chalk-faint); text-align: center; }
  footer.bottom p { margin: 0.25rem 0; }
  footer.bottom a { color: var(--chalk-dim); text-decoration: underline; }
  footer.bottom a:hover { color: var(--chalk); }

  @media print {
    body::before { display: none; }
    body { background: #fff; }
    header.top, footer.bottom, .panel, .print-btn, .warning, .tape { display: none !important; }
    .shell { max-width: none; padding: 0; margin: 0; }
    .layout { display: block; }
    .worksheet-wrap { padding-top: 0; }
    .worksheet { box-shadow: none; transform: none; border-radius: 0; padding: 0; }
  }
</style>
</head>
<body>
<div class="shell">
  <header class="top">
    <h1>Příklady</h1>
    <div class="chalk-underline"></div>
    <p>generátor úloh na procvičování aritmetiky</p>
  </header>

  <div class="layout">
    <form method="post" class="panel" autocomplete="off">
      <div class="field-group">
        <label class="field-label" for="grade-fieldset">Třída</label>
        <div class="grade-list" id="grade-fieldset">
          <label class="grade-row">
            <input type="radio" name="grade_preset" value="1" <?= $gradePreset === '1' ? 'checked' : '' ?>>
            <span class="num">1. třída</span><span class="desc">sčítání a odčítání do 20</span>
          </label>
          <label class="grade-row">
            <input type="radio" name="grade_preset" value="2" <?= $gradePreset === '2' ? 'checked' : '' ?>>
            <span class="num">2. třída</span><span class="desc">do 100, úvod do násobilky</span>
          </label>
          <label class="grade-row">
            <input type="radio" name="grade_preset" value="3" <?= $gradePreset === '3' ? 'checked' : '' ?>>
            <span class="num">3. třída</span><span class="desc">do 1 000, malá násobilka</span>
          </label>
          <label class="grade-row">
            <input type="radio" name="grade_preset" value="4" <?= $gradePreset === '4' ? 'checked' : '' ?>>
            <span class="num">4. třída</span><span class="desc">do 1 000, velká násobilka</span>
          </label>
          <label class="grade-row">
            <input type="radio" name="grade_preset" value="5" <?= $gradePreset === '5' ? 'checked' : '' ?>>
            <span class="num">5. třída</span><span class="desc">+ desetinná čísla</span>
          </label>
          <label class="grade-row">
            <input type="radio" name="grade_preset" value="all" <?= $gradePreset === 'all' ? 'checked' : '' ?>>
            <span class="num">Vše</span><span class="desc">+ záporná čísla</span>
          </label>
        </div>
        <p class="hint">Viz podrobné nastavení.</p>
      </div>

      <div class="field-group">
        <label class="field-label" for="f-count">Počet příkladů</label>
        <input type="number" id="f-count" name="count" value="<?= htmlspecialchars((string) $count) ?>" min="1" max="100">
      </div>

      <div class="field-group">
        <label class="toggle-row"><input type="checkbox" name="show_results" <?= $showResults ? 'checked' : '' ?>> Zobrazit výsledky</label>
      </div>

      <div class="field-group seed-group">
        <div class="toggle-row">
          <label class="contents-label"><input type="checkbox" name="use_seed" <?= $useSeed ? 'checked' : '' ?>> Zadat seed</label>
          <span class="info-tip" tabindex="0">?
            <span class="info-tip-popup">Seed je "semínko" pro generování — se stejným seedem a stejným zadáním dostaneš pořád stejnou sadu příkladů. Bez zaškrtnutí se při každém vygenerování vylosuje nový.</span>
          </span>
        </div>
        <div class="seed-input">
          <input type="number" name="seed" value="<?= htmlspecialchars((string) $seed) ?>" placeholder="Seed">
        </div>
      </div>

      <details class="advanced">
        <summary>Zobrazit podrobné nastavení</summary>
        <div class="advanced-body">
          <h4>Povolené operace</h4>
          <div class="field-group">
            <div class="op-row" role="group" aria-label="Povolené operace">
              <?php foreach ($operatorMap as $key => $op): ?>
                <label class="op-chip">
                  <input type="checkbox" name="operators[]" value="<?= htmlspecialchars($key) ?>" <?= in_array($op, $operators, true) ? 'checked' : '' ?>>
                  <?= htmlspecialchars($op->symbol()) ?>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="field-group">
            <label class="toggle-row">
              <input type="checkbox" name="small_multiplication_table" id="f-smt" <?= $smallMultiplicationTable ? 'checked' : '' ?>>
              Malá násobilka (× a ÷ jen s čísly 1–10)
            </label>
            <p class="hint">Omezí jen násobení a dělení — zbytek příkladu (+, −, rozsah, počet operací, závorky) se generuje podle nastavení výš beze změny.</p>
          </div>

          <div class="field-group">
            <label class="field-label" for="f-ops-count">Počet operací na příklad</label>
            <input type="number" id="f-ops-count" name="operations_count" value="<?= htmlspecialchars((string) $operationsCount) ?>" min="1" max="8">
          </div>
          <div class="row">
            <div class="field-group">
              <label class="field-label" for="f-min">Min. hodnota</label>
              <input type="number" id="f-min" name="min" value="<?= htmlspecialchars((string) $min) ?>">
            </div>
            <div class="field-group">
              <label class="field-label" for="f-max">Max. hodnota</label>
              <input type="number" id="f-max" name="max" value="<?= htmlspecialchars((string) $max) ?>">
            </div>
          </div>

          <h4>Další jevy</h4>
          <label class="toggle-row"><input type="checkbox" name="allow_negative" <?= $allowNegative ? 'checked' : '' ?>> Záporná čísla</label>
          <label class="toggle-row"><input type="checkbox" name="allow_parentheses" <?= $allowParentheses ? 'checked' : '' ?>> Závorky</label>
          <label class="toggle-row"><input type="checkbox" name="allow_priority" <?= $allowOperatorPriority ? 'checked' : '' ?>> Priorita operátorů</label>
          <div class="field-group decimals-group">
            <label class="toggle-row"><input type="checkbox" name="allow_decimals" <?= $allowDecimals ? 'checked' : '' ?>> Desetinná čísla</label>
            <div class="decimal-places-input">
              <label class="field-label" for="f-decimal-places">Počet desetinných míst</label>
              <input type="number" id="f-decimal-places" name="decimal_places" value="<?= htmlspecialchars((string) $decimalPlaces) ?>" min="1" max="2">
            </div>
          </div>

          <h4>Seed</h4>
          <label class="toggle-row"><input type="checkbox" name="include_seed_info" <?= $includeSeedInAssignment ? 'checked' : '' ?>> Zahrnout seed a konfiguraci do zadání</label>

          <details class="advanced nested">
            <summary>Pokročilé</summary>
            <div class="advanced-body">
              <div class="field-group">
                <label class="field-label" for="f-double-neg">Omezit zápisy typu "a + (-b)" / "a - (-b)" (v %)</label>
                <div class="unit-field">
                  <input type="number" id="f-double-neg" name="double_negative_bias" value="<?= htmlspecialchars((string) $doubleNegativeBiasPercent) ?>" min="0" max="100" step="10">
                  <span class="unit">%</span>
                </div>
              </div>
              <div class="field-group">
                <label class="field-label" for="f-max-neg-one">Max. počet "-1" jako činitele/dělitele</label>
                <input type="number" id="f-max-neg-one" name="max_negative_one_factors" value="<?= htmlspecialchars((string) $maxNegativeOneFactors) ?>" min="0" max="5">
              </div>
              <label class="toggle-row">
                <input type="checkbox" name="digit_count_bias" <?= $digitCountBiasEnabled ? 'checked' : '' ?>>
                Vyrovnat zastoupení počtu cifer
              </label>
              <div class="field-group whole-number-bias-group">
                <label class="field-label" for="f-whole-number-bias">Bias k celým číslům (0 % = vypnuto — pro procvičování desetinných čísel; 100 % = skoro vždy celá)</label>
                <div class="unit-field">
                  <input type="number" id="f-whole-number-bias" name="whole_number_bias" value="<?= htmlspecialchars((string) $wholeNumberBiasPercent) ?>" min="0" max="100" step="10">
                  <span class="unit">%</span>
                </div>
              </div>
              <div class="field-group">
                <label class="field-label">Pravděpodobnost operátorů (v %, normalizuje se mezi povolenými)</label>
                <div class="weight-grid">
                  <div class="field-group">
                    <label class="field-label" for="f-weight-add">+</label>
                    <div class="unit-field">
                      <input type="number" id="f-weight-add" name="weight_add" value="<?= htmlspecialchars((string) $operatorWeights['add']) ?>" min="0" max="100">
                      <span class="unit">%</span>
                    </div>
                  </div>
                  <div class="field-group">
                    <label class="field-label" for="f-weight-sub">−</label>
                    <div class="unit-field">
                      <input type="number" id="f-weight-sub" name="weight_sub" value="<?= htmlspecialchars((string) $operatorWeights['sub']) ?>" min="0" max="100">
                      <span class="unit">%</span>
                    </div>
                  </div>
                  <div class="field-group">
                    <label class="field-label" for="f-weight-mul">×</label>
                    <div class="unit-field">
                      <input type="number" id="f-weight-mul" name="weight_mul" value="<?= htmlspecialchars((string) $operatorWeights['mul']) ?>" min="0" max="100">
                      <span class="unit">%</span>
                    </div>
                  </div>
                  <div class="field-group">
                    <label class="field-label" for="f-weight-div">÷</label>
                    <div class="unit-field">
                      <input type="number" id="f-weight-div" name="weight_div" value="<?= htmlspecialchars((string) $operatorWeights['div']) ?>" min="0" max="100">
                      <span class="unit">%</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </details>
        </div>
      </details>

      <button type="submit" class="generate">Vygenerovat příklady</button>
    </form>

    <div class="worksheet-wrap">
      <?php if ($examples !== []): ?><div class="tape"></div><?php endif; ?>
      <div class="worksheet">
        <div class="head">
          <h2>Zadání</h2>
          <?php if ($examples !== []): ?>
            <button type="button" class="print-btn" id="btn-print">🖨 Tisk</button>
          <?php endif; ?>
        </div>

        <?php if ($priorityParensWarning): ?>
          <div class="warning">
            Priorita operátorů je vypnutá a závorky jsou zakázané, přitom jsou zvolené operace z obou skupin
            (+ / − i × / ÷). Generování s velkou pravděpodobností selže — zkuste povolit závorky, prioritu,
            nebo použít operace jen z jedné skupiny.
          </div>
        <?php endif; ?>

        <?php if ($errorMessage !== null): ?>
          <div class="error"><?= htmlspecialchars($errorMessage) ?></div>
        <?php endif; ?>

        <?php if ($examples !== []): ?>
          <ol class="examples">
            <?php foreach ($examples as $example): ?>
              <li><?= htmlspecialchars($serializer->render($example)) ?> =</li>
            <?php endforeach; ?>
          </ol>

          <?php if ($showResults): ?>
            <p class="results-block">
              <strong>Výsledky:</strong>
              <?php
                $numbered = [];
                foreach ($examples as $i => $example) {
                    $numbered[] = ($i + 1) . '. ' . $serializer->renderValue($example);
                }
              ?>
              <?= htmlspecialchars(implode(', ', $numbered)) ?>
            </p>
          <?php endif; ?>

          <?php if ($includeSeedInAssignment): ?>
            <p class="seed-info"><?= htmlspecialchars(formatConfigSummary($config, $seed)) ?></p>
          <?php endif; ?>
        <?php elseif ($errorMessage === null): ?>
          <p class="placeholder-text">
            <?= $submitted ? 'Zadání nevygenerovalo žádné příklady.' : 'Vyber vlevo třídu (nebo si zadání sestav sám) a klikni na „Vygenerovat příklady".' ?>
          </p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <footer class="bottom">
    <p>Generátor příkladů · <a href="https://github.com/Adewzen/priklady">zdrojový kód na GitHubu</a></p>
    <p>© <?= date('Y') ?> Jakub Nezveda · licencováno pod <a href="https://github.com/Adewzen/priklady/blob/master/LICENSE">GNU GPL v3.0</a> · obsah stránky vytvořen s pomocí AI (Claude Code)</p>
  </footer>
</div>

<script>
  document.getElementById('btn-print')?.addEventListener('click', () => window.print());

  // Preset ročníku je čistě klientská pomůcka: při výběru přednastaví skutečná pole
  // formuláře (operators[], min, max, ...). Server o presetech nic neví — vidí jen
  // výsledné hodnoty polí, přesně jako při ručním vyplnění.
  const GRADE_PRESETS = {
    '1':   { operationsCount: 1, min: 0,     max: 20,   operators: ['add', 'sub'],               parentheses: false, priority: false, decimals: false, negative: false, smt: false },
    '2':   { operationsCount: 2, min: 0,     max: 100,  operators: ['add', 'sub', 'mul', 'div'],  parentheses: true,  priority: false, decimals: false, negative: false, smt: true  },
    '3':   { operationsCount: 3, min: 0,     max: 1000, operators: ['add', 'sub', 'mul', 'div'],  parentheses: true,  priority: true,  decimals: false, negative: false, smt: true  },
    '4':   { operationsCount: 4, min: 0,     max: 1000, operators: ['add', 'sub', 'mul', 'div'],  parentheses: true,  priority: true,  decimals: false, negative: false, smt: false },
    '5':   { operationsCount: 5, min: 0,     max: 1000, operators: ['add', 'sub', 'mul', 'div'],  parentheses: true,  priority: true,  decimals: true,  negative: false, smt: false },
    'all': { operationsCount: 3, min: -1000, max: 1000, operators: ['add', 'sub', 'mul', 'div'],  parentheses: true,  priority: true,  decimals: true,  negative: true,  smt: false },
  };

  function setChecked(name, value) {
    const el = document.querySelector(`[name="${name}"]`);
    if (el) el.checked = value;
  }

  function applyGradePreset(key) {
    const preset = GRADE_PRESETS[key];
    if (!preset) return;

    document.querySelector('[name="operations_count"]').value = preset.operationsCount;
    document.querySelector('[name="min"]').value = preset.min;
    document.querySelector('[name="max"]').value = preset.max;

    document.querySelectorAll('[name="operators[]"]').forEach((checkbox) => {
      checkbox.checked = preset.operators.includes(checkbox.value);
    });

    setChecked('allow_parentheses', preset.parentheses);
    setChecked('allow_priority', preset.priority);
    setChecked('allow_decimals', preset.decimals);
    setChecked('allow_negative', preset.negative);
    setChecked('small_multiplication_table', preset.smt);
  }

  document.querySelectorAll('[name="grade_preset"]').forEach((radio) => {
    radio.addEventListener('change', () => applyGradePreset(radio.value));
  });
</script>
</body>
</html>
