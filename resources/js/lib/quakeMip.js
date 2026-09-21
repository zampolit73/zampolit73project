export const QUAKE_PALETTE = new Uint8Array([
0,0,0,15,15,15,31,31,31,47,47,47,63,63,63,75,75,75,91,91,91,107,107,107,
123,123,123,139,139,139,155,155,155,171,171,171,187,187,187,203,203,203,219,219,219,235,235,235,
15,11,7,23,15,11,31,23,11,39,27,15,47,35,19,55,43,23,63,47,23,75,55,27,
83,59,27,91,67,31,99,75,31,107,83,31,115,87,31,123,95,35,131,103,35,143,111,35,
11,11,15,19,19,27,27,27,39,39,39,51,47,47,63,55,55,75,63,63,87,71,71,103,
79,79,115,91,91,127,99,99,139,107,107,151,115,115,163,123,123,175,131,131,187,139,139,203,
0,0,0,7,7,0,11,11,0,19,19,0,27,27,0,35,35,0,43,43,7,47,47,7,
55,55,7,63,63,7,71,71,7,75,75,11,83,83,11,91,91,11,99,99,11,107,107,15,
7,0,0,15,0,0,23,0,0,31,0,0,39,0,0,47,0,0,55,0,0,63,0,0,
71,0,0,79,0,0,87,0,0,95,0,0,103,0,0,111,0,0,119,0,0,127,0,0,
19,19,0,27,27,0,35,35,0,47,43,0,55,47,0,67,55,0,75,59,7,87,67,7,
95,71,7,107,75,11,119,83,15,131,87,19,139,91,19,151,95,27,163,99,31,175,103,35,
35,19,7,47,23,11,59,31,15,75,35,19,87,43,23,99,47,31,115,55,35,127,59,43,
143,67,51,159,79,51,175,99,47,191,119,47,207,143,43,223,171,39,239,203,31,255,243,27,
11,7,0,27,19,0,43,35,15,55,43,19,71,51,27,83,55,35,99,63,43,111,71,51,
127,83,63,139,95,71,155,107,83,167,123,95,183,135,107,195,147,123,211,163,139,227,179,151,
171,139,163,159,127,151,147,115,135,139,103,123,127,91,111,119,83,99,107,75,87,95,63,75,
87,55,67,75,47,55,67,39,47,55,31,35,43,23,27,35,19,19,23,11,11,15,7,7,
187,115,159,175,107,143,163,95,131,151,87,119,139,79,107,127,75,95,115,67,83,107,59,75,
95,51,63,83,43,55,71,35,43,59,31,35,47,23,27,35,19,19,23,11,11,15,7,7,
219,195,187,203,179,167,191,163,155,175,151,139,163,135,123,151,123,111,135,111,95,123,99,83,
107,87,71,95,75,59,83,63,51,67,51,39,55,43,31,39,31,23,27,19,15,15,11,7,
111,131,123,103,123,111,95,115,103,87,107,95,79,99,87,71,91,79,63,83,71,55,75,63,
47,67,55,43,59,47,35,51,39,31,43,31,23,35,23,15,27,19,11,19,11,7,11,7,
255,243,27,239,223,23,219,203,19,203,183,15,187,167,15,171,151,11,155,131,7,139,115,7,
123,99,7,107,83,0,91,71,0,75,55,0,59,43,0,43,31,0,27,15,0,11,7,0,
0,0,255,11,11,239,19,19,223,27,27,207,35,35,191,43,43,175,47,47,159,47,47,143,
47,47,127,47,47,111,47,47,95,43,43,79,35,35,63,27,27,47,19,19,31,11,11,15,
43,0,0,59,0,0,75,7,0,95,7,0,111,15,0,127,23,7,147,31,7,163,39,11,
183,51,15,195,75,27,207,99,43,219,127,59,227,151,79,231,171,95,239,191,119,247,211,139,
167,123,59,183,155,55,199,195,55,231,227,87,127,191,255,171,231,255,215,255,255,103,0,0,
139,0,0,179,0,0,215,0,0,255,0,0,255,243,147,255,247,199,255,255,255,159,91,83
]);

const NORMAL_END=239;
const LUT=new Uint8Array(32*32*32);
const clamp=(v)=>Math.max(0,Math.min(255,v));
for(let r=0;r<32;r++)for(let g=0;g<32;g++)for(let b=0;b<32;b++){
 let best=0,dist=Infinity,rr=(r<<3)|4,gg=(g<<3)|4,bb=(b<<3)|4;
 for(let i=0;i<=NORMAL_END;i++){let o=i*3,dr=rr-QUAKE_PALETTE[o],dg=gg-QUAKE_PALETTE[o+1],db=bb-QUAKE_PALETTE[o+2],d=dr*dr+dg*dg+db*db;if(d<dist){dist=d;best=i;if(!d)break;}}
 LUT[(r<<10)|(g<<5)|b]=best;
}
const nearest=(r,g,b)=>LUT[((clamp(r)>>3)<<10)|((clamp(g)>>3)<<5)|(clamp(b)>>3)];

export function validateTextureDimensions(w,h){
 if(!Number.isInteger(w)||!Number.isInteger(h)||w<16||h<16) throw new Error('Размер текстуры должен быть не меньше 16×16 пикселей.');
 if((w&15)||(h&15)) throw new Error('Quake 1 MIP требует ширину и высоту, кратные 16.');
 if(w>4096||h>4096) throw new Error('Для браузерной обработки размер ограничен 4096×4096 пикселей.');
}
export function textureNameFromFilename(filename){
 const base=String(filename).replace(/^.*[\\/]/,'').replace(/\.[^.]+$/,'').normalize('NFKD').replace(/[\u0300-\u036f]/g,'');
 const clean=base.toLowerCase().replace(/\s+/g,'_').replace(/[^a-z0-9_{}+\-]/g,'_').replace(/_+/g,'_').replace(/^_+|_+$/g,'');
 return (clean||'texture').slice(0,15);
}
export function reserveTextureName(filename,used){
 const base=textureNameFromFilename(filename); let candidate=base,counter=2;
 while(used.has(candidate)){const suffix='_'+counter;candidate=base.slice(0,15-suffix.length)+suffix;counter++;}
 used.add(candidate); return candidate;
}
export function archiveName(date=new Date()){
 const pad=(v)=>String(v).padStart(2,'0');
 return 'converted'+pad(date.getDate())+pad(date.getMonth()+1)+date.getFullYear()+'.zip';
}
function quantizeBase(rgba,w,h,transparent){
 const out=new Uint8Array(w*h); let cr=new Float32Array(w+2),cg=new Float32Array(w+2),cb=new Float32Array(w+2);
 for(let y=0;y<h;y++){const nr=new Float32Array(w+2),ng=new Float32Array(w+2),nb=new Float32Array(w+2),forward=(y&1)===0,start=forward?0:w-1,end=forward?w:-1,step=forward?1:-1;
  for(let x=start;x!==end;x+=step){const p=(y*w+x)*4,q=y*w+x,a=rgba[p+3];if(transparent&&a<128){out[q]=255;continue;}
   const e=x+1,r=clamp(rgba[p]+cr[e]),g=clamp(rgba[p+1]+cg[e]),b=clamp(rgba[p+2]+cb[e]),idx=nearest(r,g,b),o=idx*3;out[q]=idx;
   const er=r-QUAKE_PALETTE[o],eg=g-QUAKE_PALETTE[o+1],eb=b-QUAKE_PALETTE[o+2];
   if(forward){cr[e+1]+=er*7/16;cg[e+1]+=eg*7/16;cb[e+1]+=eb*7/16;nr[e-1]+=er*3/16;ng[e-1]+=eg*3/16;nb[e-1]+=eb*3/16;nr[e]+=er*5/16;ng[e]+=eg*5/16;nb[e]+=eb*5/16;nr[e+1]+=er/16;ng[e+1]+=eg/16;nb[e+1]+=eb/16;}
   else{cr[e-1]+=er*7/16;cg[e-1]+=eg*7/16;cb[e-1]+=eb*7/16;nr[e+1]+=er*3/16;ng[e+1]+=eg*3/16;nb[e+1]+=eb*3/16;nr[e]+=er*5/16;ng[e]+=eg*5/16;nb[e]+=eb*5/16;nr[e-1]+=er/16;ng[e-1]+=eg/16;nb[e-1]+=eb/16;}
  } cr=nr;cg=ng;cb=nb;
 } return out;
}
function mipLevel(base,w,h,level,transparent){
 const block=1<<level,mw=w>>level,mh=h>>level,out=new Uint8Array(mw*mh);let rr=0,rg=0,rb=0;
 for(let y=0;y<mh;y++)for(let x=0;x<mw;x++){let sr=0,sg=0,sb=0,visible=0,hasTrans=false;
  for(let yy=0;yy<block;yy++)for(let xx=0;xx<block;xx++){const idx=base[(y*block+yy)*w+x*block+xx];if(transparent&&idx===255){hasTrans=true;continue;}const o=idx*3;sr+=QUAKE_PALETTE[o];sg+=QUAKE_PALETTE[o+1];sb+=QUAKE_PALETTE[o+2];visible++;}
  const q=y*mw+x;if(transparent&&hasTrans){out[q]=255;continue;}if(!visible){out[q]=transparent?255:0;continue;}
  const r=clamp(sr/visible+rr),g=clamp(sg/visible+rg),b=clamp(sb/visible+rb),idx=nearest(r,g,b),o=idx*3;out[q]=idx;rr=r-QUAKE_PALETTE[o];rg=g-QUAKE_PALETTE[o+1];rb=b-QUAKE_PALETTE[o+2];
 } return out;
}
export function convertRgbaToMip(rgba,w,h,textureName){
 validateTextureDimensions(w,h);
 if(!(rgba instanceof Uint8Array||rgba instanceof Uint8ClampedArray)) throw new TypeError('RGBA data must be a typed byte array.');
 if(rgba.length!==w*h*4) throw new Error('RGBA buffer size does not match image dimensions.');
 const name=textureNameFromFilename(textureName),transparent=name.startsWith('{'),levels=[quantizeBase(rgba,w,h,transparent)];
 for(let level=1;level<4;level++) levels.push(mipLevel(levels[0],w,h,level,transparent));
 const offsets=[40];for(let i=1;i<4;i++)offsets[i]=offsets[i-1]+levels[i-1].length;
 const buffer=new ArrayBuffer(offsets[3]+levels[3].length),bytes=new Uint8Array(buffer),view=new DataView(buffer),nameBytes=new TextEncoder().encode(name.slice(0,15));
 bytes.set(nameBytes.subarray(0,15),0);view.setUint32(16,w,true);view.setUint32(20,h,true);
 for(let i=0;i<4;i++){view.setUint32(24+i*4,offsets[i],true);bytes.set(levels[i],offsets[i]);}
 return bytes;
}
