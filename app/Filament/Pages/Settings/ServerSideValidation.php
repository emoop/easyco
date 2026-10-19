<?php

namespace App\Filament\Pages\Settings;

use Filament\Schemas\Components\Component;
use Illuminate\Validation\ValidationException;

/**
 * UX-0: "Save does nothing" must never happen. Two traps, found by a real-browser save audit (tests/Browser/admin-save-audit.mjs):
 *
 *  1. Filament prints the HTML `required` attribute on its inputs, so the BROWSER refuses to submit a form with an empty required field and shows only a tooltip
 *     on the first one: for a field inside a collapsed block it cannot even do that (it logs "not focusable" and nothing else), and Livewire never hears about the
 *     click. `novalidate` on the form hands every check to the server, whose messages Filament shows under the field.
 *  2. A refusal that comes from OUR code (a writer or validator that maps its messages onto form fields) is thrown as a ValidationException, but Filament only opens
 *     collapsed sections, tabs and repeater items for a failure it dispatched itself (`form-validation-error`). `failFields()` dispatches that event first.
 */
trait ServerSideValidation
{
    /**
     * The page's `<form>` without the browser's own validation.
     *
     * @template TForm of Component
     *
     * @param  TForm  $form
     * @return TForm
     */
    protected static function withoutNativeValidation(Component $form): Component
    {
        return $form->extraAttributes(['novalidate' => true], merge: true);
    }

    /**
     * Throws the messages as the form's own field errors AND opens whatever collapsed block holds the first one.
     *
     * @param  array<string, string|list<string>>  $messages  field path (with the `data.` prefix) => message
     *
     * @throws ValidationException
     */
    protected function failFields(array $messages): never
    {
        $this->dispatch('form-validation-error', livewireId: $this->getId());

        throw ValidationException::withMessages($messages);
    }
}
