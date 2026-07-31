<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case CreditCard = 'credit_card';
    case DebitCard = 'debit_card';
    case Paypal = 'paypal';
    case BankTransfer = 'bank_transfer';
    case Cash = 'cash';
    // Cambodian payment rails used by the frontend.
    case AbaPay = 'aba_pay';
    case Acleda = 'acleda';
    case Wing = 'wing';
}
