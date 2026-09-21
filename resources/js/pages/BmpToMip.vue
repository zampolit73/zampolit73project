<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import JSZip from 'jszip';
import AppLayout from '../layouts/AppLayout.vue';
import { archiveName, convertRgbaToMip, reserveTextureName, validateTextureDimensions } from '../lib/quakeMip.js';

const fileInput=ref(null),files=ref([]),errors=ref([]),converting=ref(false),progress=ref(0),progressLabel=ref(''),convertedCount=ref(0),lastArchive=ref('');
const hasFiles=computed(()=>files.value.length>0),canConvert=computed(()=>hasFiles.value&&!converting.value);
function reset(){errors.value=[];progress.value=0;progressLabel.value='';convertedCount.value=0;lastArchive.value='';}
function setFiles(list){const selected=Array.from(list).filter(f=>/\.bmp$/i.test(f.name)).sort((a,b)=>a.name.localeCompare(b.name,'ru'));files.value=selected;reset();if(!selected.length)errors.value=[{file:'Папка',message:'BMP-файлы не найдены.'}];}
async function collect(handle,out=[]){for await(const entry of handle.values()){if(entry.kind==='file'&&/\.bmp$/i.test(entry.name))out.push(await entry.getFile());else if(entry.kind==='directory')await collect(entry,out);}return out;}
async function chooseImages(){if(converting.value)return;if('showDirectoryPicker'in window){try{setFiles(await collect(await window.showDirectoryPicker({mode:'read'})));return;}catch(e){if(e?.name==='AbortError')return;}}if(fileInput.value){fileInput.value.value='';fileInput.value.click();}}
function handleInput(e){setFiles(e.target.files||[]);}
async function decodeBmp(file){let source,cleanup=()=>{};if('createImageBitmap'in window){source=await createImageBitmap(file);cleanup=()=>source.close();}else{const url=URL.createObjectURL(file),image=new Image();await new Promise((res,rej)=>{image.onload=res;image.onerror=()=>rej(new Error('Браузер не смог прочитать BMP.'));image.src=url;});source=image;cleanup=()=>URL.revokeObjectURL(url);}
 try{const width=source.width||source.naturalWidth,height=source.height||source.naturalHeight;validateTextureDimensions(width,height);const canvas=document.createElement('canvas');canvas.width=width;canvas.height=height;const ctx=canvas.getContext('2d',{willReadFrequently:true});if(!ctx)throw new Error('Canvas недоступен в этом браузере.');ctx.drawImage(source,0,0);return{width,height,rgba:ctx.getImageData(0,0,width,height).data};}finally{cleanup();}}
function download(blob,name){const url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download=name;document.body.appendChild(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);}
async function convertAll(){if(!canConvert.value)return;converting.value=true;errors.value=[];convertedCount.value=0;lastArchive.value='';progress.value=0;const zip=new JSZip(),used=new Set();let success=0;
 try{for(let i=0;i<files.value.length;i++){const file=files.value[i];progressLabel.value='Конвертация: '+file.name;progress.value=Math.round(i/files.value.length*85);try{const d=await decodeBmp(file),name=reserveTextureName(file.name,used);zip.file(name+'.mip',convertRgbaToMip(d.rgba,d.width,d.height,name));success++;}catch(e){errors.value.push({file:file.name,message:e instanceof Error?e.message:'Неизвестная ошибка конвертации.'});}if((i&3)===3)await new Promise(r=>requestAnimationFrame(r));}
 if(!success){progress.value=0;progressLabel.value='Нет файлов, пригодных для конвертации.';return;}progressLabel.value='Упаковка ZIP…';const blob=await zip.generateAsync({type:'blob',compression:'DEFLATE',compressionOptions:{level:6}},m=>{progress.value=85+Math.round(m.percent*.15);});const name=archiveName();download(blob,name);convertedCount.value=success;lastArchive.value=name;progress.value=100;progressLabel.value='Готово.';}finally{converting.value=false;}}
</script>

<template><AppLayout><Head title="BMP → MIP / Quake 1" />
<main class="mip-page">
<div class="quake-watermark" aria-hidden="true"><div class="quake-watermark__ring"></div><div class="quake-watermark__word">QUAKE</div></div>
<Link href="/projects" class="mip-back">← ПРОЕКТЫ</Link>
<section class="mip-converter" aria-labelledby="mip-title">
<p class="mip-converter__eyebrow">QUAKE 1 / MIP TEXTURE CONVERTER</p><h1 id="mip-title">BMP → MIP</h1>
<p class="mip-converter__intro">Массовая конвертация выполняется прямо в браузере. Исходные изображения не загружаются на сервер.</p>
<input ref="fileInput" class="mip-file-input" type="file" accept=".bmp,image/bmp" multiple webkitdirectory directory @change="handleInput">
<button type="button" class="mip-upload" :disabled="converting" @click="chooseImages">Загрузить изображения</button>
<div v-if="hasFiles" class="mip-selection" aria-live="polite"><strong>{{ files.length }} BMP</strong><span>{{ files.slice(0,3).map(f=>f.name).join(' · ') }}{{ files.length>3?' · …':'' }}</span></div>
<button v-if="hasFiles" type="button" class="mip-convert" :disabled="!canConvert" @click="convertAll">{{ converting?'Конвертация…':'Конвертировать' }}</button>
<div v-if="converting||progress>0" class="mip-progress" aria-live="polite"><div class="mip-progress__line"><span :style="{width:progress+'%'}"></span></div><div class="mip-progress__text">{{ progress }}% — {{ progressLabel }}</div></div>
<p v-if="convertedCount" class="mip-success">Готово: {{ convertedCount }} файлов → <strong>{{ lastArchive }}</strong></p>
<div v-if="errors.length" class="mip-errors" role="status"><strong>Пропущено: {{ errors.length }}</strong><ul><li v-for="error in errors.slice(0,8)" :key="error.file+error.message"><b>{{ error.file }}</b> — {{ error.message }}</li></ul><p v-if="errors.length>8">И ещё {{ errors.length-8 }} ошибок.</p></div>
<div class="mip-rules"><span>Размеры: кратны 16</span><span>4 mip-уровня</span><span>Quake 1 palette / 256</span><span>Без случайных fullbright</span></div>
</section></main></AppLayout></template>
