<?php

declare(strict_types=1);

namespace Attestwire\Exception;

use Attestwire\Finding;
use Attestwire\ValidationResult;

/**
 * `Client::generate()` was refused because the invoice breaks a rule (HTTP 422).
 *
 * The API never writes XML or a PDF it knows a receiver would reject.
 * `getResult()` is the same `ValidationResult` `Client::validate()` would
 * return for this invoice: `->errors()` names each rule, what is wrong and the
 * fix. Nothing is charged against your quota for a refusal.
 */
final class InvalidInvoiceException extends ApiException
{
    public function __construct(private readonly ValidationResult $result)
    {
        $errors = $result->errors();
        $rules = implode(', ', array_map(static fn (Finding $f): string => $f->rule ?? '?', $errors));
        $count = count($errors);

        parent::__construct(
            422,
            'invoice_invalid',
            sprintf(
                'The invoice does not pass %d rule%s (%s), so nothing was generated. '
                    . 'See getResult()->errors() for each fix.',
                $count,
                $count === 1 ? '' : 's',
                $rules !== '' ? $rules : 'no rule named'
            )
        );
    }

    public function getResult(): ValidationResult
    {
        return $this->result;
    }
}
