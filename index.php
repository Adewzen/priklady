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

$count = max(1, min(100, readInt($input, 'count', 10)));
$operationsCount = max(1, min(8, readInt($input, 'operations_count', 3)));
$min = readFloat($input, 'min', -1000);
$max = readFloat($input, 'max', 1000);
if ($min > $max) {
    [$min, $max] = [$max, $min];
}

$selectedOperatorKeys = $submitted ? ($input['operators'] ?? []) : ['add', 'sub', 'mul', 'div'];
$operators = [];
foreach ($selectedOperatorKeys as $key) {
    if (isset($operatorMap[$key])) {
        $operators[] = $operatorMap[$key];
    }
}
if ($operators === []) {
    $operators = [Operator::Add, Operator::Sub];
}

$allowNegative = readBool($input, 'allow_negative');
$allowDecimals = readBool($input, 'allow_decimals');
$decimalPlaces = max(1, min(2, readInt($input, 'decimal_places', 2)));
$allowParentheses = readBool($input, 'allow_parentheses');
$allowOperatorPriority = readBool($input, 'allow_priority');
$showResults = readBool($input, 'show_results');
$useSeed = readBool($input, 'use_seed');
$seed = $useSeed
    ? readInt($input, 'seed', random_int(1, 1_000_000))
    : random_int(1, 1_000_000);

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
);

$priorityParensWarning = $submitted
    && !$allowOperatorPriority
    && !$allowParentheses
    && $config->usesMultiplePrecedenceClasses();

$serializer = new Serializer($config);
$examples = [];
$errorMessage = null;

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
<title>Příklady na procvičování</title>
<style>
  body { font-family: system-ui, sans-serif; margin: 0; padding: 0; color: #222; }
  header, footer { background: #223; color: #fff; padding: 0.75rem 1.5rem; }
  footer { font-size: 0.85rem; color: #ccc; }
  .layout { display: flex; gap: 2rem; padding: 1.5rem; align-items: flex-start; flex-wrap: wrap; }
  .form-column { flex: 0 0 320px; }
  .results-column { flex: 1; min-width: 280px; }
  fieldset { margin-bottom: 1rem; border: 1px solid #ccc; border-radius: 4px; }
  legend { font-weight: 600; padding: 0 0.4rem; }
  label { display: block; margin: 0.35rem 0; }
  .row { display: flex; gap: 0.5rem; }
  .row label { flex: 1; }
  input[type=number] { width: 100%; box-sizing: border-box; }
  button { padding: 0.5rem 1.2rem; cursor: pointer; }
  ol.examples { font-size: 1.15rem; line-height: 2.2; }
  .error { background: #fdd; border: 1px solid #c00; padding: 0.75rem; margin-bottom: 1rem; border-radius: 4px; }
  .warning { background: #ffe9b3; border: 1px solid #cc8800; padding: 0.75rem; margin-bottom: 1rem; border-radius: 4px; }
  .result { color: #555; }
  .seed-info { color: #777; font-size: 0.85rem; margin: 0 0 0.5rem; }
</style>
</head>
<body>
<header><strong>Příklady</strong> — generátor úloh na procvičování aritmetiky</header>

<div class="layout">
  <div class="form-column">
    <form method="post">
      <fieldset>
        <legend>Zadání</legend>
        <label>Počet příkladů
          <input type="number" name="count" value="<?= htmlspecialchars((string) $count) ?>" min="1" max="100">
        </label>
        <label>Počet operací na příklad
          <input type="number" name="operations_count" value="<?= htmlspecialchars((string) $operationsCount) ?>" min="1" max="8">
        </label>
        <div class="row">
          <label>Min. hodnota
            <input type="number" name="min" value="<?= htmlspecialchars((string) $min) ?>">
          </label>
          <label>Max. hodnota
            <input type="number" name="max" value="<?= htmlspecialchars((string) $max) ?>">
          </label>
        </div>
      </fieldset>

      <fieldset>
        <legend>Operace</legend>
        <?php foreach ($operatorMap as $key => $op): ?>
          <label>
            <input type="checkbox" name="operators[]" value="<?= htmlspecialchars($key) ?>" <?= in_array($op, $operators, true) ? 'checked' : '' ?>>
            <?= htmlspecialchars($op->symbol()) ?>
          </label>
        <?php endforeach; ?>
      </fieldset>

      <fieldset>
        <legend>Další jevy</legend>
        <label><input type="checkbox" name="allow_negative" <?= $allowNegative ? 'checked' : '' ?>> Záporná čísla</label>
        <label><input type="checkbox" name="allow_decimals" <?= $allowDecimals ? 'checked' : '' ?>> Desetinná čísla</label>
        <label>Počet desetinných míst
          <input type="number" name="decimal_places" value="<?= htmlspecialchars((string) $decimalPlaces) ?>" min="1" max="2">
        </label>
        <label><input type="checkbox" name="allow_parentheses" <?= $allowParentheses ? 'checked' : '' ?>> Závorky</label>
        <label><input type="checkbox" name="allow_priority" <?= $allowOperatorPriority ? 'checked' : '' ?>> Priorita operátorů (× a ÷ před + a −)</label>
      </fieldset>

      <fieldset>
        <legend>Zobrazení</legend>
        <label><input type="checkbox" name="show_results" <?= $showResults ? 'checked' : '' ?>> Zobrazit výsledky</label>
        <label><input type="checkbox" name="use_seed" <?= $useSeed ? 'checked' : '' ?>> Použít zadaný seed (jinak se při každém běhu vygeneruje nový)</label>
        <label>Seed (pro opakovatelné vygenerování stejné dávky)
          <input type="number" name="seed" value="<?= htmlspecialchars((string) $seed) ?>">
        </label>
      </fieldset>

      <button type="submit">Vygenerovat</button>
    </form>
  </div>

  <div class="results-column">
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
      <p class="seed-info">Seed: <?= htmlspecialchars((string) $seed) ?></p>
      <ol class="examples">
        <?php foreach ($examples as $example): ?>
          <li>
            <?= htmlspecialchars($serializer->render($example)) ?> =
            <?php if ($showResults): ?>
              <span class="result"><?= htmlspecialchars($serializer->renderValue($example)) ?></span>
            <?php else: ?>
              <span class="result">&hellip;</span>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ol>
    <?php elseif ($errorMessage !== null): ?>
      <!-- chyba už je zobrazená výše, tady nic dalšího netřeba -->
    <?php elseif ($submitted): ?>
      <p>Zadání nevygenerovalo žádné příklady.</p>
    <?php else: ?>
      <p>Nastavte zadání vlevo a klikněte na „Vygenerovat".</p>
    <?php endif; ?>
  </div>
</div>

<footer>Generátor příkladů — verze pro lokální testování, bez vizuálního designu.</footer>
</body>
</html>
