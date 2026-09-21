import assert from 'node:assert/strict';
import { QUAKE_PALETTE, archiveName, convertRgbaToMip, reserveTextureName, textureNameFromFilename, validateTextureDimensions } from '../../resources/js/lib/quakeMip.js';
assert.equal(QUAKE_PALETTE.length,768);
assert.equal(textureNameFromFilename('My Stone Texture.bmp'),'my_stone_textur');
assert.equal(archiveName(new Date(2026,8,21)),'converted21092026.zip');
const used=new Set();assert.equal(reserveTextureName('same.bmp',used),'same');assert.equal(reserveTextureName('same.bmp',used),'same_2');
assert.throws(()=>validateTextureDimensions(17,16),/кратные 16/);
const w=16,h=16,rgba=new Uint8ClampedArray(w*h*4);for(let i=0;i<w*h;i++){const o=i*4;rgba[o]=15;rgba[o+1]=15;rgba[o+2]=15;rgba[o+3]=255;}
const mip=convertRgbaToMip(rgba,w,h,'TEST.BMP'),view=new DataView(mip.buffer,mip.byteOffset,mip.byteLength);
assert.equal(mip.length,380);assert.equal(view.getUint32(16,true),16);assert.equal(view.getUint32(20,true),16);
assert.deepEqual([0,1,2,3].map(l=>view.getUint32(24+l*4,true)),[40,296,360,376]);
for(let i=40;i<mip.length;i++)assert.equal(mip[i],1);
console.log('Quake MIP encoder tests passed.');
