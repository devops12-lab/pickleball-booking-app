<?php

namespace App\Enums;

enum TenantPaymentMethodType: string
{
    case Cash = 'cash';
    case Gcash = 'gcash';
    case Maya = 'maya';
    case QrPh = 'qrph';
    case BankTransfer = 'bank_transfer';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Gcash => 'GCash',
            self::Maya => 'Maya',
            self::QrPh => 'QRPh',
            self::BankTransfer => 'Bank Transfer',
            self::Other => 'Other',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
