<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class PresensiController extends Controller
{
    public function gethari($hari)
    {
        // $hari = date("D");

        switch ($hari) {
            case 'Sun':
                $hari_ini = "Minggu";
                break;

            case 'Mon':
                $hari_ini = "Senin";
                break;

            case 'Tue':
                $hari_ini = "Selasa";
                break;

            case 'Wed':
                $hari_ini = "Rabu";
                break;

            case 'Thu':
                $hari_ini = "Kamis";
                break;

            case 'Fri':
                $hari_ini = "Jumat";
                break;

            case 'Sat':
                $hari_ini = "Sabtu";
                break;

            default:
                $hari_ini = "Tidak di ketahui";
                break;
        }

        return $hari_ini;
    }

    public function store(Request $request)
    {
        $original_data  = file_get_contents('php://input');
        $decoded_data   = json_decode($original_data, true);
        $encoded_data   = json_encode($decoded_data);

        $data           = $decoded_data['data'];
        $pin            = $data['pin'];
        $status_scan    = $data['status_scan'];
        $scan           = $data['scan'];

        $karyawan = DB::table('karyawan')->where('pin', $pin)->first();
        if ($karyawan == null) {
            return response()->json(['status' => false, 'message' => 'Pin Belum Terdaftar'], 500);
        }
        $nik = $karyawan->nik;
        $hariini = date("Y-m-d", strtotime($scan));
        $jamsekarang = date("H:i", strtotime($scan));
        $tgl_sebelumnya = date('Y-m-d', strtotime("-1 days", strtotime($hariini)));
        $cekpresensi_sebelumnya = DB::table('presensi')
            ->join('jam_kerja', 'presensi.kode_jam_kerja', '=', 'jam_kerja.kode_jam_kerja')
            ->where('tgl_presensi', $tgl_sebelumnya)
            ->where('nik', $nik)
            ->first();

        $ceklintashari_presensi = $cekpresensi_sebelumnya != null  ? $cekpresensi_sebelumnya->lintashari : 0;
        $kode_cabang = $karyawan->kode_cabang;
        $kode_dept = $karyawan->kode_dept;
        $tgl_presensi = $ceklintashari_presensi == 1 && $jamsekarang < "09:00" ? $tgl_sebelumnya : $hariini;
        $jam = $jamsekarang;

        // Cek Jam Kerja Karyawan
        $namahari = $this->gethari(date('D', strtotime($tgl_presensi)));
        $jamkerja = DB::table('konfigurasi_jamkerja_by_date')
            ->join('jam_kerja', 'konfigurasi_jamkerja_by_date.kode_jam_kerja', '=', 'jam_kerja.kode_jam_kerja')
            ->where('nik', $nik)
            ->where('tanggal', $tgl_presensi)
            ->first();

        //Jika Tidak Memiliki Jam Kerja By Date
        if ($jamkerja == null) {
            //Cek Jam Kerja harian / Jam Kerja Khusus / Jam Kerja Per Orangannya
            $jamkerja = DB::table('konfigurasi_jamkerja')
                ->join('jam_kerja', 'konfigurasi_jamkerja.kode_jam_kerja', '=', 'jam_kerja.kode_jam_kerja')
                ->where('nik', $nik)->where('hari', $namahari)->first();

            // Jika Jam Kerja Harian Kosong
            if ($jamkerja == null) {
                $jamkerja = DB::table('konfigurasi_jk_dept_detail')
                    ->join('konfigurasi_jk_dept', 'konfigurasi_jk_dept_detail.kode_jk_dept', '=', 'konfigurasi_jk_dept.kode_jk_dept')
                    ->join('jam_kerja', 'konfigurasi_jk_dept_detail.kode_jam_kerja', '=', 'jam_kerja.kode_jam_kerja')
                    ->where('kode_dept', $kode_dept)
                    ->where('kode_cabang', $kode_cabang)
                    ->where('hari', $namahari)->first();
            }
        }

        $presensi = DB::table('presensi')->where('tgl_presensi', $tgl_presensi)->where('nik', $nik);
        $cek = $presensi->count();
        $datapresensi = $presensi->first();
        $tgl_pulang = $jamkerja->lintashari == 1 ? date('Y-m-d', strtotime("+ 1 days", strtotime($tgl_presensi))) : $tgl_presensi;
        $jam_pulang = $hariini . " " . $jam;
        $jamkerja_pulang = $tgl_pulang . " " . $jamkerja->jam_pulang;
        $datakaryawan = DB::table('karyawan')->where('nik', $nik)->first();
        $no_hp = $datakaryawan->no_hp;

        if ($status_scan == '1') {
            if ($jam_pulang < $jamkerja_pulang) {
                return response()->json(['status' => false, 'message' => 'Belum Waktu Pulang'], 500);
            } else if (!empty($datapresensi->jam_out)) {
                return response()->json(['status' => false, 'message' => 'Sudah Absen'], 500);
            } else {
                $data_pulang = [
                    'jam_out' => $jam,
                ];
                if ($cek > 0) {
                    DB::table('presensi')->where('tgl_presensi', $tgl_presensi)->where('nik', $nik)->update($data_pulang);
                    return response()->json(['status' => true, 'message' => 'Updated'], 200);
                } else {
                    DB::table('presensi')->insert([
                        'nik' => $nik,
                        'tgl_presensi' => $tgl_presensi,
                        'jam_out' => $jam,
                        'kode_jam_kerja' => $jamkerja->kode_jam_kerja,
                        'status' => 'h'
                    ]);
                    return response()->json(['status' => true, 'message' => 'Presensi Created'], 200);
                }
            }
        } else {
            if ($jam < $jamkerja->awal_jam_masuk) {
                return response()->json(['status' => false, 'message' => 'Belum Waktu Absen Masuk'], 500);
            } else if ($jam > $jamkerja->akhir_jam_masuk) {
                return response()->json(['status' => false, 'message' => 'Waktu Absen Habis'], 500);
            } else {
                $cekpresensimasuk = $presensi->first();
                if ($cekpresensimasuk && $cekpresensimasuk->jam_in != null) {
                    return response()->json(['stauts' => false, 'message' => 'Sudah Absen']);
                }
                $data = [
                    'nik' => $nik,
                    'tgl_presensi' => $tgl_presensi,
                    'jam_in' => $jam,
                    'kode_jam_kerja' => $jamkerja->kode_jam_kerja,
                    'status' => 'h'
                ];
                $simpan = DB::table('presensi')->insert($data);
                if ($simpan) {
                    return response()->json(['status' => true, 'message' => 'Presensi Created'], 200);
                } else {
                    return response()->json(['status' => false, 'message' => 'Presensi Failed'], 500);
                }
            }
        }
    }

}
