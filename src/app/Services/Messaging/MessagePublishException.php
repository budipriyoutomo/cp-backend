<?php

namespace App\Services\Messaging;

use RuntimeException;

/**
 * Pesan tidak terbukti sampai di broker. Satu tipe untuk semua sebab — koneksi
 * gagal, nack, unroutable, timeout — supaya pemanggil cukup menangkap satu.
 */
class MessagePublishException extends RuntimeException
{
}
