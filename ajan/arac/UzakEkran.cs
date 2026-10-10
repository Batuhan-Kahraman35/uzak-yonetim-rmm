// Uzak Yönetim - ekran yakalayıcı (0.3.0)
// Kullanıcı oturumunda çalışır (SYSTEM'in ekranı yoktur), tüm monitörleri JPEG olarak kaydeder.
// Ajan bu exe'yi depodan (Get-UzakPaket 'UzakEkran') indirip tek seferlik zamanlanmış görevle çağırır.
//
// Derleme (sunucuda, imzasız):
//   csc.exe /target:winexe /out:UzakEkran.exe /reference:System.Drawing.dll /reference:System.Windows.Forms.dll UzakEkran.cs
//
// Kullanım: UzakEkran.exe <cikisYolu.jpg> [kalite 10-95] [azamiGenislik px, 0=olcekleme yok]
// Çıkış kodu 0 = başarılı. Hata olursa <cikisYolu>.hata.txt yazılır ve kod 1 döner.

using System;
using System.Drawing;
using System.Drawing.Imaging;
using System.IO;
using System.Runtime.InteropServices;
using System.Windows.Forms;

static class UzakEkran
{
    [DllImport("user32.dll")] static extern bool SetProcessDpiAwarenessContext(IntPtr value);
    [DllImport("user32.dll")] static extern bool SetProcessDPIAware();

    [STAThread]
    static int Main(string[] args)
    {
        if (args.Length < 1)
        {
            Console.Error.WriteLine("Kullanim: UzakEkran.exe <cikisYolu.jpg> [kalite] [azamiGenislik]");
            return 2;
        }

        string cikis = args[0];
        long kalite = args.Length > 1 ? Clamp(Parse(args[1], 60), 10, 95) : 60;
        int azami = args.Length > 2 ? (int)Clamp(Parse(args[2], 2560), 0, 10000) : 2560;

        // Ölçekli ekranda (%125, %150) kırpık görüntü olmasın: önce monitör bazlı, olmazsa sistem DPI farkındalığı.
        // Herhangi bir GDI çağrısından önce yapılmalı.
        try { if (!SetProcessDpiAwarenessContext((IntPtr)(-4))) SetProcessDPIAware(); }
        catch { try { SetProcessDPIAware(); } catch { } }

        try
        {
            Rectangle b = SystemInformation.VirtualScreen;
            using (Bitmap tam = new Bitmap(b.Width, b.Height, PixelFormat.Format24bppRgb))
            {
                using (Graphics g = Graphics.FromImage(tam))
                    g.CopyFromScreen(b.Left, b.Top, 0, 0, tam.Size);

                Bitmap cikti = tam;
                Bitmap kucuk = null;
                try
                {
                    if (azami > 0 && tam.Width > azami)
                    {
                        int y = (int)((long)tam.Height * azami / tam.Width);
                        kucuk = new Bitmap(azami, y, PixelFormat.Format24bppRgb);
                        using (Graphics g = Graphics.FromImage(kucuk))
                        {
                            g.InterpolationMode = System.Drawing.Drawing2D.InterpolationMode.HighQualityBicubic;
                            g.DrawImage(tam, 0, 0, azami, y);
                        }
                        cikti = kucuk;
                    }

                    ImageCodecInfo enc = null;
                    foreach (ImageCodecInfo c in ImageCodecInfo.GetImageEncoders())
                        if (c.MimeType == "image/jpeg") { enc = c; break; }

                    using (EncoderParameters prm = new EncoderParameters(1))
                    {
                        prm.Param[0] = new EncoderParameter(Encoder.Quality, kalite);
                        // Yarım dosya okunmasın: geçici ada yaz, sonra taşı
                        string gecici = cikis + ".tmp";
                        cikti.Save(gecici, enc, prm);
                        if (File.Exists(cikis)) File.Delete(cikis);
                        File.Move(gecici, cikis);
                    }
                }
                finally { if (kucuk != null) kucuk.Dispose(); }
            }
            return 0;
        }
        catch (Exception ex)
        {
            try { File.WriteAllText(cikis + ".hata.txt", ex.Message); } catch { }
            Console.Error.WriteLine(ex.Message);
            return 1;
        }
    }

    static long Parse(string s, long varsayilan)
    {
        long v;
        return long.TryParse(s, out v) ? v : varsayilan;
    }

    static long Clamp(long v, long alt, long ust)
    {
        return v < alt ? alt : (v > ust ? ust : v);
    }
}
