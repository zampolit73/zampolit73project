# BMP → Quake 1 MIP converter

Route: `/projects/bmp-to-mip`.

## Processing model

Conversion is fully client-side. Selected images are decoded and converted in the browser and are not uploaded to the Laravel server.

The preferred browser flow uses the File System Access API directory picker. Browsers without it fall back to a multiple file/directory input.

## Input

Only files with the `.bmp` extension are processed.

Quake MIP output dimensions must be at least 16×16 and divisible by 16.

Input BMP files no longer need to satisfy that restriction themselves. The browser automatically computes the nearest safe Quake canvas, scales the image uniformly without changing its aspect ratio, centers it, and fills the small remaining border by extending edge pixels. This avoids both stretching and hard black letterbox borders.

Example: a 1000×700 source becomes a 1008×704 Quake texture canvas while the image content keeps its original aspect ratio.

The web implementation applies a 4096×4096 safety ceiling to avoid unreasonable browser memory use. Oversized images are proportionally downscaled before conversion.

Unreadable files are skipped individually so one bad image does not cancel a batch.

## Texture names

The embedded `miptex_t` name is ASCII-safe, lower-case and limited to 15 characters plus the terminating NUL byte required by the 16-byte field.

Name collisions after cleanup/truncation receive suffixes such as `_2`, `_3`.

A name beginning with `{` enables transparent-pixel handling with palette index 255.

## Palette and quantization

The encoder contains the classic 256-color Quake palette.

For ordinary source pixels it chooses indices 0–239. This avoids accidentally producing fullbright pixels when arbitrary RGB artwork happens to be closer to the special bright range.

Base-level conversion uses error-diffused nearest-color quantization. Mip levels are generated from the quantized base texture by averaging palette RGB values over 2×2, 4×4 and 8×8 source blocks and re-quantizing with carried residual error.

## Binary MIP layout

Each output `.mip` is a raw Quake 1 `miptex_t` lump:

```text
char name[16]
uint32 width
uint32 height
uint32 offsets[4]
byte mip0[width * height]
byte mip1[(width/2) * (height/2)]
byte mip2[(width/4) * (height/4)]
byte mip3[(width/8) * (height/8)]
```

All integer fields are little-endian.

## ZIP output

Successful files are added to a DEFLATE-compressed ZIP archive named:

`convertedDDMMYYYY.zip`

Example:

`converted21092026.zip`

The browser starts the download automatically when packaging completes.
