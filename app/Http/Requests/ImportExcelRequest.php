<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;

class ImportExcelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv'],
            'source' => ['required', 'string', 'in:lazada,shopee,tokopedia'],
        ];
    }
}