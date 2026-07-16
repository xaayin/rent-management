<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Payment;
use Spatie\LaravelPdf\Enums\Format;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * Printable/PDF receipt for a recorded payment (FR-PAY-01), showing the
 * rent/fine allocation split (FR-PAY-02).
 *
 * Addressed by payment row, but rendered for the whole receipt: one handover of
 * money can settle several invoices, and the tenant is owed a single document
 * showing where all of it went — not one page per invoice.
 */
class ReceiptPdfController extends Controller
{
    public function __invoke(Payment $payment): PdfBuilder
    {
        abort_if($payment->isReversal(), 404);

        $receipt = $payment->receipt;

        abort_if($receipt === null, 404);

        $receipt->load([
            'tenant',
            'payments.invoice.lease.property',
            'payments.reversal',
        ]);

        return Pdf::view('pdf.receipt', ['receipt' => $receipt])
            ->format(Format::A4)
            ->inline('receipt-'.str_replace('/', '-', $receipt->number).'.pdf');
    }
}
