<?php

namespace App\Domain\Projects\Imports;

use App\Domain\Employment\Imports\MasterImports;
use App\Domain\Organisation\Imports\Concerns\ReadsMasterRows;
use App\Domain\Projects\Models\Customer;
use Illuminate\Database\Eloquent\Model;

/**
 * Data import DI-1 (Core → Customers): the organisations projects are run for, by code, with a contact, address,
 * country and tax registration number.
 */
final class CustomerImports extends MasterImports
{
    use ReadsMasterRows;

    public const TYPE = 'customers';

    public static function fields(): array
    {
        return [
            'code' => ['Code', true], 'name' => ['Name', true], 'contact_name' => ['Contact name', false], 'email' => ['E-mail', false], 'phone' => ['Phone', false],
            'address' => ['Address', false], 'country_code' => ['Country code', false], 'tax_id' => ['Tax id', false], 'status' => ['Status', false],
        ];
    }

    public function label(): string
    {
        return 'Customers upload';
    }

    protected function module(): string
    {
        return 'projects';
    }

    protected function model(): string
    {
        return Customer::class;
    }

    protected function aliases(): array
    {
        return ['customer_code' => 'code', 'customer_name' => 'name', 'customer' => 'name', 'contact' => 'contact_name', 'contact_person' => 'contact_name', 'email' => 'email',
            'email_id' => 'email', 'mobile' => 'phone', 'contact_number' => 'phone', 'country' => 'country_code', 'gstin' => 'tax_id', 'gst_number' => 'tax_id', 'tax_id' => 'tax_id', 'vat_number' => 'tax_id'];
    }

    protected function attributes(array $r, array &$context, ?Model $existing, array &$errors): array
    {
        $email = $this->text($r['email'] ?? null, 255, 'E-mail', $errors);
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = "E-mail '{$email}' is not an e-mail address.";
            $email = null;
        }
        $taxId = $this->text($r['tax_id'] ?? null, 32, 'Tax id', $errors);

        return array_filter([
            'name' => $this->text($r['name'] ?? null, 160, 'Name', $errors),
            'contact_name' => $this->text($r['contact_name'] ?? null, 120, 'Contact name', $errors),
            'email' => $email === null ? null : mb_strtolower($email),
            'phone' => $this->text($r['phone'] ?? null, 32, 'Phone', $errors),
            'address' => $this->text($r['address'] ?? null, 500, 'Address', $errors),
            'country_code' => $this->country($r['country_code'] ?? null, $errors),
            'tax_id' => $taxId === null ? null : strtoupper($taxId),
            'status' => $this->status($r['status'] ?? null, $errors),
        ], fn ($v) => $v !== null);
    }

    public function format(): array
    {
        return [
            'columns' => [
                ['Code', 'Yes', 'The customer code (up to 32 characters). A code on file updates that customer.', 'GLOBEX'],
                ['Name', 'Yes', 'The customer\'s name.', 'Globex Corporation'],
                ['Contact name', 'No', 'The person you deal with.', 'Priya Nair'],
                ['E-mail', 'No', 'The contact\'s e-mail address.', 'priya.nair@globex.example'],
                ['Phone', 'No', 'The contact\'s phone (up to 32 characters).', '+91 80 4000 1000'],
                ['Address', 'No', 'Billing address (up to 500 characters).', '5th floor, Prestige Tower, Bengaluru 560001'],
                ['Country code', 'No', 'Two-letter ISO country code.', 'IN'],
                ['Tax id', 'No', 'The customer\'s tax registration number, e.g. its GST or VAT number (up to 32 characters).', '29ABCDE1234F1Z5'],
                ['Status', 'No', 'active or inactive. Blank for a new customer is active.', 'active'],
            ],
            'notes' => [
                'Rows are matched on Code (any case). A new code adds a customer; a code on file updates it; a row whose values are already on file is skipped.',
                'Blank cells keep what is on file. Customers are organisations; individual contacts of vendors and partners are kept as business partners.',
            ],
        ];
    }

    protected function examples(): array
    {
        return [
            ['GLOBEX', 'Globex Corporation', 'Priya Nair', 'priya.nair@globex.example', '+91 80 4000 1000', '5th floor, Prestige Tower, Bengaluru 560001', 'IN', '29ABCDE1234F1Z5', 'active'],
            ['INITECH', 'Initech Ltd', 'Tom Smykowski', 'tom@initech.example', '+44 20 7946 0000', '1 Silicon Way, London', 'GB', 'GB123456789', 'active'],
        ];
    }
}
