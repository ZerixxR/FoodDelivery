# 🚴 FoodDelivery App

![PHP](https://img.shields.io/badge/PHP-8.x-777BB4?style=flat&logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-8.x-4479A1?style=flat&logo=mysql&logoColor=white)
![Bootstrap](https://img.shields.io/badge/Bootstrap-5.3.3-7952B3?style=flat&logo=bootstrap&logoColor=white)
![Tailwind](https://img.shields.io/badge/Tailwind-3.x-06B6D4?style=flat&logo=tailwindcss&logoColor=white)
![JavaScript](https://img.shields.io/badge/JavaScript-ES6-F7DF1E?style=flat&logo=javascript&logoColor=black)
![Status](https://img.shields.io/badge/Status-Production%20Ready-28A745?style=flat)

---

## 📋 Deskripsi Proyek

**FoodDelivery App** adalah platform pesan antar makanan berbasis web yang dikembangkan sebagai Tugas Besar Pemrograman Web. Aplikasi ini menghubungkan **Pembeli**, **Restoran (Seller)**, **Driver**, dan **Admin** dalam satu ekosistem terintegrasi dengan fitur otomatisasi sistem yang canggih.

### 🎯 Tujuan
- Mempermudah pemesanan makanan secara online
- Menyediakan platform bagi restoran untuk menjual produk
- Memberikan lapangan kerja bagi driver pengiriman
- Mengelola seluruh proses dari pemesanan hingga pengiriman

---

## ✨ Fitur Lengkap

### 👤 Pembeli (Buyer)
| Fitur | Deskripsi |
|-------|-----------|
| Register/Login | Pendaftaran akun dengan validasi dan login aman |
| Browse Menu | Melihat daftar menu dengan filter kategori & search |
| Keranjang Belanja | Tambah, update, dan hapus item dari keranjang |
| Checkout | Form alamat, pilihan metode pembayaran (COD, Transfer, E-Wallet) |
| Upload Bukti Bayar | Upload bukti transfer untuk verifikasi admin |
| Tracking Real-time | Pantau status pesanan dengan polling 10 detik |
| Review & Rating | Beri ulasan dan rating untuk produk yang sudah diterima |
| Notifikasi | Menerima notifikasi status pesanan |

### 🏪 Restoran (Seller)
| Fitur | Deskripsi |
|-------|-----------|
| Register (Verifikasi Admin) | Pendaftaran menunggu persetujuan admin |
| Dashboard | Statistik menu, pesanan, dan pendapatan |
| Kelola Menu | CRUD produk dengan upload gambar |
| Kelola Pesanan | Update status pesanan (confirmed → cooking → on_delivery) |
| Stok Otomatis | Stok berkurang otomatis saat pesanan dibuat |
| Notifikasi | Notifikasi pesanan masuk dan update status |

### 🛵 Driver
| Fitur | Deskripsi |
|-------|-----------|
| Register (Verifikasi Admin) | Pendaftaran menunggu persetujuan admin |
| Dashboard | Pengiriman aktif dan riwayat pengiriman |
| Detail Pengiriman | Lihat detail pesanan, alamat, dan item |
| Update Status | Konfirmasi on_delivery dan delivered |
| Tracking | Peta simulasi dengan animasi titik bergerak |

### 🛡️ Admin
| Fitur | Deskripsi |
|-------|-----------|
| Dashboard | Statistik lengkap dengan grafik revenue |
| Verifikasi User | Verifikasi/tolak seller dan driver baru |
| Verifikasi Pembayaran | Verifikasi/tolak bukti transfer dengan catatan |
| Kelola Pesanan | Update status dan assign driver ke pesanan |
| Kelola User | Aktifkan/nonaktifkan user |
| Export CSV | Download data pesanan sesuai filter |

### ⚡ System Automation
| Fitur | Deskripsi |
|-------|-----------|
| Auto Update Stok | Stok produk berkurang saat checkout |
| Auto Kalkulasi Ongkir | Simulasi ongkir berdasarkan kota tujuan |
| Auto Generate Invoice | File `.txt` di `uploads/invoices/` |
| Auto Notifikasi | Notifikasi ke semua pihak terkait |
| Auto Update Status | Status berubah otomatis sesuai alur |

---

## 🛠️ Cara Install

### 📌 Prasyarat
- XAMPP / WAMP / MAMP (PHP 8.x + MySQL 8.x)
- Web browser (Chrome/Firefox/Edge)
- VS Code atau text editor

### 📥 Langkah Instalasi

#### 1. Clone atau Download Proyek
```bash
git clone https://github.com/username/fooddelivery.git
# atau download ZIP dan extract ke htdocs