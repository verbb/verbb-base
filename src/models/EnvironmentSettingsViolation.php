<?php
namespace verbb\base\models;

/**
 * Why `EnvironmentSettings::violation()` refused a set of settings, and which setting caused it.
 */
final class EnvironmentSettingsViolation
{
    // Constants
    // =========================================================================

    // A setting refers to an environment variable that an admin didn't save.
    public const REFERENCE = 'reference';

    // A connection's destination or provider changed while it still carries credentials the user can't see.
    public const CREDENTIALS = 'credentials';


    // Properties
    // =========================================================================

    public readonly string $reason;
    public readonly array $path;


    // Public Methods
    // =========================================================================

    public function __construct(string $reason, array $path)
    {
        $this->reason = $reason;
        $this->path = $path;
    }

    /**
     * The top-level setting the error belongs to, such as `apiKey`.
     */
    public function getAttribute(): ?string
    {
        $attribute = $this->path[0] ?? null;

        return is_string($attribute) ? $attribute : null;
    }
}
