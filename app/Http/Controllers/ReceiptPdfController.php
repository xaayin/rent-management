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
 */
class ReceiptPdfController extends Controller
{
    public function __invoke(Payment $payment): PdfBuilder
    {
        abort_if($payment->isReversal(), 404);

        $payment->load(['invoice.lease.tenant', 'invoice.lease.property']);

        return Pdf::view('pdf.receipt', ['payment' => $payment])
            ->format(Format::A4)
            ->inline('receipt-'.str_replace('/', '-', (string) $payment->receipt_number).'.pdf');
    }
}
