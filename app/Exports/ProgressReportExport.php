<?php

namespace App\Exports;

use Illuminate\Support\Collection;

// NOTE: This class is a skeleton to use with maatwebsite/excel if you decide to install it.
// composer require maatwebsite/excel
// Then implement FromCollection / WithHeadings interfaces as needed.

class ProgressReportExport
{
    protected $rows;

    public function __construct(Collection $rows)
    {
        $this->rows = $rows;
    }

    // Example method if you implement FromCollection
    public function collection()
    {
        return $this->rows;
    }
}
