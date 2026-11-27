<?php

use verbb\base\helpers\EnvironmentSettings;
use verbb\base\models\EnvironmentSettingsViolation;

$vendorPath = getenv('VERBB_BASE_CRAFT_VENDOR') ?: dirname(__DIR__) . '/vendor';

if (!is_file($vendorPath . '/autoload.php')) {
    throw new RuntimeException('Set VERBB_BASE_CRAFT_VENDOR to a Craft 5 vendor directory.');
}

require $vendorPath . '/autoload.php';
require $vendorPath . '/yiisoft/yii2/Yii.php';
require $vendorPath . '/craftcms/cms/src/Craft.php';
require dirname(__DIR__) . '/src/models/EnvironmentSettingsViolation.php';
require dirname(__DIR__) . '/src/helpers/EnvironmentSettings.php';

putenv('FIXTURE_SECRET=fixture-secret-value');
$_SERVER['FIXTURE_SECRET'] = 'fixture-secret-value';

final class EnvironmentSettingsFixtureApp
{
    public bool $admin = false;
    public bool $console = false;

    public function getRequest(): object
    {
        return new class($this->console) {
            public function __construct(private bool $console)
            {
            }

            public function getIsConsoleRequest(): bool
            {
                return $this->console;
            }
        };
    }

    public function getUser(): object
    {
        return new class($this->admin) {
            public function __construct(private bool $admin)
            {
            }

            public function getIsAdmin(): bool
            {
                return $this->admin;
            }
        };
    }
}

function fixtureAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function fixtureReason(?EnvironmentSettingsViolation $violation): ?string
{
    return $violation?->reason;
}

$app = new EnvironmentSettingsFixtureApp();
Craft::$app = $app;

// Reference detection matches what `App::parseEnv()` resolves.
foreach (['$FIXTURE_SECRET', '${FIXTURE_SECRET}', 'https://${FIXTURE_SECRET}.example.com', '/$FIXTURE_SECRET/', 'https://example.com/$FIXTURE_SECRET', '$UNSET_FIXTURE_VARIABLE'] as $value) {
    fixtureAssert(EnvironmentSettings::containsReference($value), "`$value` must count as a reference.");
}

foreach (['plain-key', 'pa$$word', 'price: $5', '', null, 42, '@webroot'] as $value) {
    fixtureAssert(!EnvironmentSettings::containsReference($value), 'A literal value must not count as a reference: ' . var_export($value, true));
}

fixtureAssert(EnvironmentSettings::containsReference(['headers' => [['value' => '$FIXTURE_SECRET']]]), 'Nested references must be found.');

// Non-admins can't add references.
$violation = EnvironmentSettings::violation(['apiKey' => '$FIXTURE_SECRET'], ['apiKey' => 'literal']);
fixtureAssert(fixtureReason($violation) === EnvironmentSettingsViolation::REFERENCE, 'A new reference must be refused.');
fixtureAssert($violation->getAttribute() === 'apiKey', 'The violation must name the setting.');

$violation = EnvironmentSettings::violation(['headers' => [['value' => '${FIXTURE_SECRET}']]], []);
fixtureAssert(fixtureReason($violation) === EnvironmentSettingsViolation::REFERENCE, 'A nested reference must be refused on a new record.');
fixtureAssert($violation->path === ['headers', 0, 'value'], 'The violation must record the nested path.');

fixtureAssert(EnvironmentSettings::violation(['apiKey' => 'typed-key', 'name' => 'Updated'], ['apiKey' => '$FIXTURE_SECRET']) === null, 'Replacing a reference with a literal must be allowed.');
fixtureAssert(EnvironmentSettings::violation(['apiKey' => '$FIXTURE_SECRET', 'listId' => '12'], ['apiKey' => '$FIXTURE_SECRET', 'listId' => '11']) === null, 'A reference an admin saved can stay.');
fixtureAssert(fixtureReason(EnvironmentSettings::violation(['apiKey' => '$OTHER_FIXTURE'], ['apiKey' => '$FIXTURE_SECRET'])) === EnvironmentSettingsViolation::REFERENCE, 'Swapping one reference for another must be refused.');
fixtureAssert(fixtureReason(EnvironmentSettings::violation(['secret' => '$FIXTURE_SECRET'], ['apiKey' => '$FIXTURE_SECRET'])) === EnvironmentSettingsViolation::REFERENCE, 'A saved reference can only stay in its own setting.');

// Changing where credentials go means entering variable-backed credentials again.
$stored = ['apiUrl' => 'https://api.example.com', 'apiKey' => '$FIXTURE_SECRET', 'listId' => '11'];

fixtureAssert(fixtureReason(EnvironmentSettings::violation(['apiUrl' => 'https://attacker.example'], $stored)) === EnvironmentSettingsViolation::CREDENTIALS, 'A changed URL must not carry an omitted variable-backed credential.');
fixtureAssert(fixtureReason(EnvironmentSettings::violation(['apiUrl' => 'https://attacker.example', 'apiKey' => '$FIXTURE_SECRET'], $stored)) === EnvironmentSettingsViolation::CREDENTIALS, 'A changed URL must not carry a reposted variable-backed credential.');
fixtureAssert(EnvironmentSettings::violation(['apiUrl' => 'https://attacker.example', 'apiKey' => 'their-own-key'], $stored) === null, 'A changed URL with re-entered credentials must be allowed.');
fixtureAssert(EnvironmentSettings::violation(['apiUrl' => 'https://api.example.com', 'listId' => '12'], $stored) === null, 'Unchanged destinations must not ask for credentials.');
fixtureAssert(EnvironmentSettings::violation(['apiUrl' => 'https://attacker.example'], ['apiUrl' => 'https://api.example.com', 'apiKey' => 'literal']) === null, 'Literal credentials the user can see may be carried.');
fixtureAssert(fixtureReason(EnvironmentSettings::violation(['apiUrl' => 'https://attacker.example'], ['apiUrl' => 'https://api.example.com', 'apiKey' => 'masked'], ['apiKey'], true)) === EnvironmentSettingsViolation::CREDENTIALS, 'Masked credentials must not be carried to a new destination.');
fixtureAssert(fixtureReason(EnvironmentSettings::violation(['listId' => '12'], $stored, providerChanged: true)) === EnvironmentSettingsViolation::CREDENTIALS, 'A provider change must not carry variable-backed credentials.');

// Explicit destinations replace the name pattern.
fixtureAssert(fixtureReason(EnvironmentSettings::violation(['region' => 'eu'], ['region' => 'us', 'apiKey' => '$FIXTURE_SECRET'], destinations: ['region'])) === EnvironmentSettingsViolation::CREDENTIALS, 'An explicit destination must be honoured.');
fixtureAssert(EnvironmentSettings::violation(['apiUrl' => 'https://attacker.example'], $stored, destinations: []) === null, 'An empty destination list must not match by name.');

// Explicit credentials limit which saved settings need re-entry.
fixtureAssert(EnvironmentSettings::violation(['apiUrl' => 'https://attacker.example'], $stored, ['token']) === null, 'Only listed credentials must need re-entry.');

// Admins and console requests are trusted.
$app->admin = true;
fixtureAssert(EnvironmentSettings::violation(['apiUrl' => 'https://attacker.example', 'apiKey' => '$OTHER_FIXTURE'], $stored) === null, 'Admins must be able to use references.');
$app->admin = false;
$app->console = true;
fixtureAssert(EnvironmentSettings::violation(['apiKey' => '$OTHER_FIXTURE'], []) === null, 'Console requests must be able to use references.');

echo "Environment settings fixture passed.\n";
