<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Receipt;
use Illuminate\Support\Facades\Auth;
use Spatie\LaravelPdf\Enums\Format;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * The tenant portal's own PDF doors (T2). Same templates as the staff routes —
 * the tenant receives the identical document — but gated on OWNERSHIP under
 * the tenant guard, not on a staff permission. 404 (not 403) when the record
 * belongs to someone else: the response must not confirm the id exists.
 */
class PortalPdfController extends Controller
{
    public function invoice(Invoice $invoice): PdfBuilder
    {
        abort_unless($invoice->lease->tenant_id === $this->tenantId(), 404);

        $invoice->load(['lease.tenant', 'lease.property', 'lineItems']);

        return Pdf::view('pdf.invoice', ['invoice' => $invoice])
            ->format(Format::A4)
            ->inline('invoice-'.str_replace('/', '-', $invoice->number).'.pdf');
    }

    public function receipt(Receipt $receipt): PdfBuilder
    {
        abort_unless($receipt->tenant_id === $this->tenantId(), 404);

        $receipt->load(['tenant', 'payments.invoice.lease.property']);

        return Pdf::view('pdf.receipt', ['receipt' => $receipt])
            ->format(Format::A4)
            ->inline('receipt-'.str_replace('/', '-', $receipt->number).'.pdf');
    }

    private function tenantId(): int
    {
        return (int) Auth::guard('tenant')->id();
    }
}
