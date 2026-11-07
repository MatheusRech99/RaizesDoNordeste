<?php

namespace App\Domain\Unidades;

use Illuminate\Database\Eloquent\Model;

class HorarioFuncionamento extends Model
{
    protected $table = 'horarios_funcionamento';

    public $timestamps = false;

    protected $fillable = ['unidade_id', 'dia_semana', 'abre', 'fecha'];
}
