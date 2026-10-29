<?php

namespace Tests\Unit\Services;

use App\Services\Support\Normalizer;
use Tests\TestCase;

class NormalizerTest extends TestCase
{
    public function test_ico_normalization(): void
    {
        $this->assertSame('31333532', Normalizer::ico('31333532'));
        $this->assertSame('31333532', Normalizer::ico('31 33 35 32'));
        $this->assertSame('31333532', Normalizer::ico('ICO: 31333532'));
        $this->assertNull(Normalizer::ico('123'));
        $this->assertNull(Normalizer::ico('123456789'));
        $this->assertNull(Normalizer::ico(''));
    }

    public function test_person_name_norm_handles_diacritics(): void
    {
        $this->assertSame(
            'hruska-peter',
            Normalizer::personNameNorm('Peter', 'Hruška')
        );
    }

    public function test_seat_norm(): void
    {
        $this->assertSame(
            'einsteinova-bratislava-85101',
            Normalizer::seatNorm('Einsteinova', 'Bratislava', '851 01')
        );
        $this->assertNull(Normalizer::seatNorm(null, null, null));
    }
}
