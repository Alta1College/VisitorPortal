<?php
$site = App\Models\Site::find(1);
$type = App\VisitorWorkflow\Models\VisitorType::where('slug', 'contractor')->first();
$def = app(App\VisitorWorkflow\WorkflowResolver::class)->resolve($site, $type);
echo 'fields='.implode(',', array_map(fn ($r) => $r->field->key.($r->required ? '*' : ''), $def->fields)).PHP_EOL;
echo 'conditions='.json_encode($def->field('wwc_card')->field->conditions).PHP_EOL;
$answers = ['works_with_children' => '1', 'wwc_card' => 'WWC-123456'];
echo 'visible='.implode(',', app(App\VisitorWorkflow\ConditionEvaluator::class)->visibleKeys($def, $answers)).PHP_EOL;
try {
    $r = app(App\VisitorWorkflow\ResponseValidator::class)->validate($def, $answers, [], false);
    echo 'valid='.json_encode($r->answers).PHP_EOL;
} catch (Illuminate\Validation\ValidationException $e) {
    echo 'errors='.json_encode($e->errors()).PHP_EOL;
}
