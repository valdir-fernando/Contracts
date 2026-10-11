<?php

namespace App\Services\Reports;

use setasign\Fpdi\Fpdi;

class PagedPdf extends Fpdi
{
    public function Footer()
    {
        $this->SetY(-10);
        $this->SetFont('Helvetica', '', 8);
        $this->Cell(0, 5, 'Pagina '.$this->PageNo().' / {nb}', 0, 0, 'R');
    }
}
