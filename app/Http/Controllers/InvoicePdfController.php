<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Invoice;
use Spatie\LaravelPdf\Enums\Format;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * Printable/PDF invoice (FR-INV-07) with the full itemised fine breakdown
 * (FR-INV-02, FR-FIN-12).
 */
class InvoicePdfController extends Controller
{
    public function __invoke(Invoice $invoice): PdfBuilder
    {
        $invoice->load(['lease.tenant', 'lease.property', 'lineItems']);

        return Pdf::view('pdf.invoice', ['invoice' => $invoice])
            ->format(Format::A4)
            ->inline('invoice-'.str_replace('/', '-', $invoice->number).'.pdf');
    }
}
