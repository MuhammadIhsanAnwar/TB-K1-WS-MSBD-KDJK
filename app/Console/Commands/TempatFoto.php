<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Tambahkan schema:image ke medanvisit-data.ttl berdasarkan foto di sebuah folder.
 *
 * Nama file dicocokkan dengan slug tempat. Kalau tidak persis, dicoba pencocokan
 * longgar (nama tempat tanpa "kolam-renang-" dsb). Tempat yang sudah punya gambar
 * dilewati, jadi aman dijalankan berulang.
 */
class TempatFoto extends Command
{
    protected $signature = 'tempat:foto
                            {folder : Folder sumber foto}
                            {--tulis : Tulis ke TTL dan salin foto ke public/images/foto}';

    protected $description = 'Pasang gambar tempat wisata dari folder foto';

    /** Nama file yang tidak sama persis dengan slug. */
    private const ALIAS = [
        'mora-waterpark' => 'taman-mora-indah-waterpark',
        'hairos-waterpark' => 'hairos-water-park',
        'tirta-ceria' => 'tirta-ceria-waterpark',
        'centre-point' => 'centre-point-mall',
        'plaza-medan-fari' => 'plaza-medan-fair',
        'manhattan' => 'manhattan-times-square',
        'ringroad-citywalk' => 'ringroad-city-walks',
        'plaza-millennium' => 'plaza-millenium',
        'lippo-plaza' => 'lippo-plaza-medan',
        'irian-supermarket-karya' => 'irian-supermarket',
        'museum-cina' => 'museum-situs-kota-cina',
        'rahmat-international-wildlife-museum-gallery' => 'rahmat-gallery',
        'museum-perjuangan-medan' => 'museum-perjuangan-tni',
        'kawasan-madras' => 'kawasan-kampung-madras',
        'gedung-balai-kota-lama' => 'balai-kota-lama',
        'gedung-london-sumatra' => 'gedung-lonsum',
        'kantor-pos-besar' => 'pos-bloc-medan',
        'becak-medan' => 'becak-motor',
        'dimsum-nelayan' => 'dim-sum-nelayan-nelayan-jala-jala',
        'durian-house' => 'durian-house-dh-patisserie',
        'esplanade-medan-arsip' => 'lapangan-merdeka',
        'gereja-immanuel-arsip' => 'gereja-immanuel',
        'istana-maimun-1900' => 'istana-maimun',
        'kawasan-kuliner-selat-panjang' => 'kuliner-selat-panjang',
        'masjid-raya-potret' => 'masjid-raya-al-mashun',
        'museum-negeri-sumut' => 'museum-negeri-sumatera-utara',
        'tembakau-deli-1897' => 'era-tembakau-deli',
        'tjong-a-fie-arsip' => 'tjong-a-fie',
        'taman-sri-deli-medan' => 'taman-sri-deli',
        'citraland-gamacity' => 'citraland-gama-city',
        'kampoeng-ladang-outbound' => 'kampoeng-ladang-outbound-family-recreation',
        'central-zoo-park-resort' => 'central-park-zoo-resort',
    ];

    public function handle(): int
    {
        $folder = (string) $this->argument('folder');
        $ttl = database_path('rdf/medanvisit-data.ttl');
        $tujuan = public_path('images/foto');

        if (! is_dir($folder)) {
            $this->error('Folder tidak ditemukan: '.$folder);

            return self::FAILURE;
        }

        $isi = (string) file_get_contents($ttl);

        // Slug yang ada di TTL
        preg_match_all('/^res:([a-z0-9-]+)\s+a\s+mv:/m', $isi, $m);
        $slug = array_flip($m[1]);

        $foto = [];
        foreach (glob($folder.'/*') as $jalur) {
            if (is_file($jalur) && preg_match('/\.(jpe?g|png|webp)$/i', $jalur)) {
                $foto[] = $jalur;
            }
        }
        sort($foto);

        $pasang = [];
        $lewat = [];
        $asing = [];

        foreach ($foto as $jalur) {
            $nama = pathinfo($jalur, PATHINFO_FILENAME);
            $s = self::ALIAS[strtolower($nama)] ?? strtolower($nama);

            if (! isset($slug[$s])) {
                $asing[] = basename($jalur);

                continue;
            }

            if ($this->sudahAda($isi, $s)) {
                $lewat[] = $s;

                continue;
            }

            $pasang[$s] = $jalur;
        }

        $this->info('Foto di folder : '.count($foto));
        $this->info('Akan dipasang  : '.count($pasang));
        $this->info('Sudah punya    : '.count($lewat));
        $this->info('Tidak dikenal  : '.count($asing));

        if ($asing !== []) {
            $this->line('  '.implode(', ', $asing));
        }

        if ($pasang === []) {
            $this->warn('Tidak ada yang perlu ditulis.');

            return self::SUCCESS;
        }

        foreach (array_keys($pasang) as $s) {
            $this->line('  + '.$s);
        }

        if (! $this->option('tulis')) {
            $this->warn('Belum ditulis. Jalankan dengan --tulis.');

            return self::SUCCESS;
        }

        foreach ($pasang as $s => $jalur) {
            $nama = basename($jalur);
            if (! is_dir($tujuan)) {
                mkdir($tujuan, 0755, true);
            }
            $target = $tujuan.'/'.$nama;

        if (realpath($jalur) !== realpath($target)) {
            if (! copy($jalur, $target)) {
                $this->error('Gagal menyalin '.$nama);

                return self::FAILURE;
            }
        }

            $this->buatKecil($tujuan.'/'.$nama, $tujuan.'/kecil/'.pathinfo($nama, PATHINFO_FILENAME).'.jpg');

            $isi = $this->sisipkan($isi, $s, $nama);
        }

        file_put_contents($ttl, $isi);
        $this->info('Ditulis. Jalankan rdf:kirim --replace agar DB 3 ikut berubah.');

        return self::SUCCESS;
    }

    /** Versi 720 px di images/foto/kecil, dipakai semua slot thumbnail (lihat components/foto.blade.php). */
    private function buatKecil(string $sumber, string $tujuan): void
    {
        if (! is_dir(dirname($tujuan))) {
            mkdir(dirname($tujuan), 0755, true);
        }

        $info = @getimagesize($sumber);
        if (! $info) {
            $this->warn('  ! bukan gambar: '.basename($sumber));

            return;
        }

        [$lebar, $tinggi] = $info;
        $skala = min(1, 720 / max($lebar, $tinggi));
        $w = max(1, (int) round($lebar * $skala));
        $h = max(1, (int) round($tinggi * $skala));

        $asal = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($sumber),
            IMAGETYPE_PNG => @imagecreatefrompng($sumber),
            IMAGETYPE_WEBP => @imagecreatefromwebp($sumber),
            default => null,
        };

        if (! $asal) {
            $this->warn('  ! format tidak didukung: '.basename($sumber));

            return;
        }

        $kanvas = imagecreatetruecolor($w, $h);
        imagecopyresampled($kanvas, $asal, 0, 0, 0, 0, $w, $h, $lebar, $tinggi);
        imagejpeg($kanvas, $tujuan, 82);
    }

    private function sudahAda(string $isi, string $slug): bool
    {
        // Batasi pencarian sampai blok berikutnya supaya tidak salah cocok.
        return (bool) preg_match('/^res:'.preg_quote($slug, '/').'\s+a\s+mv:(?:(?!\nres:).)*?schema:image/ms', $isi);
    }

    /** Sisipkan schema:image sebelum penutup blok (" .") milik slug. */
    private function sisipkan(string $isi, string $slug, string $berkas): string
    {
        $url = 'https://medanvisit.neoverse.my.id/images/foto/'.$berkas;
        $batas = '(?:(?!\nres:).)*?';
        $pola = '/^(res:'.preg_quote($slug, '/').'\s+a\s+mv:'.$batas.')(\n\s*\.)/ms';

        if (! preg_match($pola, $isi)) {
            $pola = '/^(res:'.preg_quote($slug, '/').'\s+a\s+mv:'.$batas.')(\s*\.\s*)$/ms';
        }

        return preg_replace($pola, '${1} ;'."\n".'    schema:image <'.$url.'>${2}', $isi, 1);
    }
}
