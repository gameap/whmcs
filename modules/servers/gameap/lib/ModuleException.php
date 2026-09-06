<?php

namespace WHMCS\Module\Server\Gameap;

/**
 * A failure the module can explain.
 *
 * WHMCS displays whatever string a provisioning function returns straight to
 * the administrator, so the message has to be readable and must never contain
 * a credential. Everything that goes into one is assembled by this module —
 * API responses are summarised, never pasted wholesale — which is what keeps
 * the panel token out of the admin UI and out of the module log.
 */
class ModuleException extends \RuntimeException
{
    public const CODE_CONFIG = 'config';
    public const CODE_TRANSPORT = 'transport';
    public const CODE_AUTH = 'auth';
    public const CODE_FORBIDDEN = 'forbidden';
    public const CODE_NOT_FOUND = 'not_found';
    public const CODE_CONFLICT = 'conflict';
    public const CODE_PANEL = 'panel';
    public const CODE_CAPACITY = 'capacity';
    public const CODE_STATE = 'state';

    private string $errorCode;

    private int $httpStatus;

    private string $panelDetail;

    public function __construct(
        string $errorCode,
        string $message,
        int $httpStatus = 0,
        ?\Throwable $previous = null,
        string $panelDetail = ''
    ) {
        parent::__construct($message, 0, $previous);

        $this->errorCode = $errorCode;
        $this->httpStatus = $httpStatus;
        $this->panelDetail = $panelDetail;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * HTTP status the panel answered with, or 0 when the request never got
     * that far. Callers use it to tell "the panel said no" from "the panel
     * was unreachable" without matching on message text — the substring
     * matching that makes other modules misclassify errors.
     */
    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * The panel's own error string, verbatim. The panel answers 403 for
     * several unrelated reasons (missing ability, non-admin token owner,
     * target user is an administrator) and the text is the only thing that
     * tells them apart.
     */
    public function panelDetail(): string
    {
        return $this->panelDetail;
    }

    public function panelDetailContains(string $needle): bool
    {
        return $needle !== '' && str_contains(mb_strtolower($this->panelDetail), mb_strtolower($needle));
    }

    public function isNotFound(): bool
    {
        return $this->errorCode === self::CODE_NOT_FOUND;
    }

    public static function config(string $message): self
    {
        return new self(self::CODE_CONFIG, $message);
    }

    public static function state(string $message): self
    {
        return new self(self::CODE_STATE, $message);
    }

    public static function capacity(string $message): self
    {
        return new self(self::CODE_CAPACITY, $message);
    }
}
