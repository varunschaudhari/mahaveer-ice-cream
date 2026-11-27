# Builds the web logo files from the client's logo (docs/brand/mahavir-logo-original.jpg).
# Windows PowerShell 5.1, from the repo root:
#   powershell -ExecutionPolicy Bypass -File docs/brand/build_logo.ps1
# When the designer's original file arrives, export it as a large JPG/PNG on white, replace the
# source and run this again. The artwork is never redrawn or recoloured: the script only removes
# the white around it and cuts it into parts:
#   logo.png          full logo with tagline        (hero without photo, admin login, link preview)
#   logo-compact.png  without the tagline line      (website header and footer)
#   logo-mark.png     the swirl-and-M on top        (small app bars, placeholders)
#   favicon.png, icon-192.png, icon-512.png, icon-512-maskable.png (mark on white), badge-72.png (white mark for Android notifications)
#   og-image.png      1200x630 link preview         (website only)
param(
    [string]$Source = 'docs/brand/mahavir-logo-original.jpg',
    [string]$Out = 'docs/brand'
)
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Drawing
Add-Type -ReferencedAssemblies System.Drawing -TypeDefinition @'
using System;
using System.Collections.Generic;
using System.Drawing;
using System.Drawing.Drawing2D;
using System.Drawing.Imaging;
using System.Runtime.InteropServices;

public static class LogoTools
{
    static int[] Read(Bitmap b)
    {
        var data = b.LockBits(new Rectangle(0, 0, b.Width, b.Height), ImageLockMode.ReadOnly, PixelFormat.Format32bppArgb);
        var px = new int[b.Width * b.Height];
        Marshal.Copy(data.Scan0, px, 0, px.Length);
        b.UnlockBits(data);
        return px;
    }

    static Bitmap Write(int[] px, int w, int h)
    {
        var b = new Bitmap(w, h, PixelFormat.Format32bppArgb);
        var data = b.LockBits(new Rectangle(0, 0, w, h), ImageLockMode.WriteOnly, PixelFormat.Format32bppArgb);
        Marshal.Copy(px, 0, data.Scan0, px.Length);
        b.UnlockBits(data);
        return b;
    }

    // The green outline of the speech bubble: everything it encloses (the white "Mahavir"
    // lettering on pink) stays as it is; only white reachable from outside becomes transparent.
    static bool IsGreen(int c)
    {
        int r = (c >> 16) & 255, g = (c >> 8) & 255, bl = c & 255;
        return r < 200 && g > 150 && bl < 120 && g > r + 10;
    }

    static int MinChannel(int c)
    {
        return Math.Min((c >> 16) & 255, Math.Min((c >> 8) & 255, c & 255));
    }

    public static Bitmap RemoveWhite(Bitmap src)
    {
        int w = src.Width, h = src.Height;
        var px = Read(src);
        var outside = new bool[w * h];
        var queue = new Queue<int>();
        for (int x = 0; x < w; x++) { queue.Enqueue(x); queue.Enqueue((h - 1) * w + x); }
        for (int y = 0; y < h; y++) { queue.Enqueue(y * w); queue.Enqueue(y * w + w - 1); }
        while (queue.Count > 0)
        {
            int i = queue.Dequeue();
            if (outside[i] || IsGreen(px[i])) continue;
            outside[i] = true;
            int x = i % w, y = i / w;
            if (x > 0) queue.Enqueue(i - 1);
            if (x < w - 1) queue.Enqueue(i + 1);
            if (y > 0) queue.Enqueue(i - w);
            if (y < h - 1) queue.Enqueue(i + w);
        }

        // Tiny pockets walled in by green (between the swirl layers) are background too; only the
        // large area inside the bubble outline is kept as it is.
        var seen = new bool[w * h];
        for (int s = 0; s < px.Length; s++)
        {
            if (outside[s] || seen[s] || IsGreen(px[s])) continue;
            var part = new List<int>();
            queue.Enqueue(s);
            seen[s] = true;
            while (queue.Count > 0)
            {
                int i = queue.Dequeue();
                part.Add(i);
                int x = i % w, y = i / w;
                int[] next = { x > 0 ? i - 1 : -1, x < w - 1 ? i + 1 : -1, y > 0 ? i - w : -1, y < h - 1 ? i + w : -1 };
                foreach (int n in next)
                {
                    if (n < 0 || seen[n] || outside[n] || IsGreen(px[n])) continue;
                    seen[n] = true;
                    queue.Enqueue(n);
                }
            }
            if (part.Count < 400) foreach (int i in part) outside[i] = true;
        }

        var background = new bool[w * h];
        for (int i = 0; i < px.Length; i++) background[i] = outside[i] && MinChannel(px[i]) >= 236;

        var result = new int[w * h];
        for (int y = 0; y < h; y++)
        {
            for (int x = 0; x < w; x++)
            {
                int i = y * w + x, c = px[i];
                if (background[i]) { result[i] = 0; continue; }
                // Pale pixels count as edge too: the JPEG blurs the narrow white gaps between the
                // swirl layers so much that they never reach the background threshold.
                bool edge = outside[i] && (MinChannel(c) >= 180 || NearBackground(background, w, h, x, y, 2));
                if (!edge) { result[i] = c | unchecked((int)0xFF000000); continue; }
                // Anti-aliased edge on white: recover coverage and the unblended colour.
                int r = (c >> 16) & 255, g = (c >> 8) & 255, bl = c & 255;
                int a = Math.Max(255 - r, Math.Max(255 - g, 255 - bl));
                if (a < 40) { result[i] = 0; continue; } // faint JPEG noise along the edge
                r = Clamp((r - (255 - a)) * 255 / a);
                g = Clamp((g - (255 - a)) * 255 / a);
                bl = Clamp((bl - (255 - a)) * 255 / a);
                result[i] = (a << 24) | (r << 16) | (g << 8) | bl;
            }
        }
        return Write(result, w, h);
    }

    static bool NearBackground(bool[] bg, int w, int h, int x, int y, int radius)
    {
        for (int dy = -radius; dy <= radius; dy++)
            for (int dx = -radius; dx <= radius; dx++)
            {
                int nx = x + dx, ny = y + dy;
                if (nx >= 0 && ny >= 0 && nx < w && ny < h && bg[ny * w + nx]) return true;
            }
        return false;
    }

    static int Clamp(int v) { return v < 0 ? 0 : (v > 255 ? 255 : v); }

    // Rows that contain logo pixels, grouped into bands separated by at least `gap` empty rows.
    public static List<int[]> Bands(Bitmap b, int gap)
    {
        var px = Read(b);
        var bands = new List<int[]>();
        int start = -1, last = -1;
        for (int y = 0; y < b.Height; y++)
        {
            bool any = false;
            for (int x = 0; x < b.Width && !any; x++) any = ((px[y * b.Width + x] >> 24) & 255) > 24;
            if (!any) continue;
            if (start < 0) start = y;
            else if (y - last > gap) { bands.Add(new[] { start, last }); start = y; }
            last = y;
        }
        if (start >= 0) bands.Add(new[] { start, last });
        return bands;
    }

    // Smallest rectangle holding the logo pixels between rows top..bottom.
    public static Rectangle Box(Bitmap b, int top, int bottom)
    {
        var px = Read(b);
        int minX = b.Width, maxX = -1, minY = bottom, maxY = top;
        for (int y = top; y <= bottom; y++)
            for (int x = 0; x < b.Width; x++)
                if (((px[y * b.Width + x] >> 24) & 255) > 24)
                {
                    if (x < minX) minX = x; if (x > maxX) maxX = x;
                    if (y < minY) minY = y; if (y > maxY) maxY = y;
                }
        return Rectangle.FromLTRB(minX, minY, maxX + 1, maxY + 1);
    }

    public static Bitmap Crop(Bitmap b, Rectangle r)
    {
        return b.Clone(r, PixelFormat.Format32bppArgb);
    }

    // `art` scaled to fit `box` and centred on a canvas (transparent unless `fill` is given).
    public static Bitmap Place(Bitmap art, int canvasW, int canvasH, int boxW, int boxH, Color fill)
    {
        double scale = Math.Min((double)boxW / art.Width, (double)boxH / art.Height);
        int w = (int)Math.Round(art.Width * scale), h = (int)Math.Round(art.Height * scale);
        var canvas = new Bitmap(canvasW, canvasH, PixelFormat.Format32bppArgb);
        using (var g = Graphics.FromImage(canvas))
        {
            g.Clear(fill);
            g.CompositingQuality = CompositingQuality.HighQuality;
            g.InterpolationMode = InterpolationMode.HighQualityBicubic;
            g.PixelOffsetMode = PixelOffsetMode.HighQuality;
            g.SmoothingMode = SmoothingMode.HighQuality;
            using (var attrs = new ImageAttributes())
            {
                attrs.SetWrapMode(WrapMode.TileFlipXY); // no dark fringe at the edges
                g.DrawImage(art, new Rectangle((canvasW - w) / 2, (canvasH - h) / 2, w, h), 0, 0, art.Width, art.Height, GraphicsUnit.Pixel, attrs);
            }
        }
        return canvas;
    }

    public static Bitmap Resize(Bitmap art, int height)
    {
        int width = (int)Math.Round(art.Width * (double)height / art.Height);
        return Place(art, width, height, width, height, Color.Transparent);
    }

    // White silhouette (Android shows notification badges as a single-colour shape).
    public static Bitmap Silhouette(Bitmap art)
    {
        var px = Read(art);
        for (int i = 0; i < px.Length; i++) px[i] = (px[i] & unchecked((int)0xFF000000)) | 0x00FFFFFF;
        return Write(px, art.Width, art.Height);
    }
}
'@

function Save-Png([System.Drawing.Bitmap]$Image, [string]$Name) {
    $path = Join-Path (Resolve-Path $Out) $Name
    $Image.Save($path, [System.Drawing.Imaging.ImageFormat]::Png)
    '{0,-24} {1,5} x {2,-5} {3,7:N0} bytes' -f $Name, $Image.Width, $Image.Height, (Get-Item $path).Length
}

$original = New-Object System.Drawing.Bitmap (Resolve-Path $Source).Path
$clean = [LogoTools]::RemoveWhite($original)
$bands = [LogoTools]::Bands($clean, 3)
'Bands (top-bottom rows): ' + (($bands | ForEach-Object { '{0}-{1}' -f $_[0], $_[1] }) -join ', ')
if ($bands.Count -lt 3) { throw 'Expected at least 3 bands (mark, bubble + "Ice Cream", tagline); check the source image.' }

$last = $bands.Count - 1
$full = [LogoTools]::Crop($clean, [LogoTools]::Box($clean, $bands[0][0], $bands[$last][1]))
# The tagline band (and the green rule above it, when it is a band of its own) is left out of the compact logo.
$compactEnd = $bands[$last - 1][1]
$ruleBox = [LogoTools]::Box($clean, $bands[$last - 1][0], $bands[$last - 1][1])
if ($ruleBox.Height -lt 12) { $compactEnd = $bands[$last - 2][1] }
$compact = [LogoTools]::Crop($clean, [LogoTools]::Box($clean, $bands[0][0], $compactEnd))
$mark = [LogoTools]::Crop($clean, [LogoTools]::Box($clean, $bands[0][0], $bands[0][1]))

Save-Png $clean 'mahavir-logo-transparent.png'
Save-Png ([LogoTools]::Resize($full, 360)) 'logo.png'
Save-Png ([LogoTools]::Resize($compact, 192)) 'logo-compact.png'
Save-Png ([LogoTools]::Resize($mark, [Math]::Min($mark.Height, 256))) 'logo-mark.png'
Save-Png ([LogoTools]::Place($mark, 64, 64, 60, 60, [System.Drawing.Color]::Transparent)) 'favicon.png'
$white = [System.Drawing.Color]::White
Save-Png ([LogoTools]::Place($mark, 192, 192, 150, 150, $white)) 'icon-192.png'
Save-Png ([LogoTools]::Place($mark, 512, 512, 400, 400, $white)) 'icon-512.png'
Save-Png ([LogoTools]::Place($mark, 512, 512, 300, 300, $white)) 'icon-512-maskable.png' # inside the 80 % safe circle
Save-Png ([LogoTools]::Place([LogoTools]::Silhouette($mark), 72, 72, 60, 60, [System.Drawing.Color]::Transparent)) 'badge-72.png'
Save-Png ([LogoTools]::Place($full, 1200, 630, 900, 470, $white)) 'og-image.png'
