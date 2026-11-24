<script setup>
import {computed,ref,watch} from 'vue';
const props=defineProps({contact:{type:Object,required:true},expanded:Boolean});
const active=ref('');const copied=ref('');const error=ref('');
watch(()=>props.contact.id,()=>{active.value='';copied.value='';error.value='';});
const labels=[['email','Почта'],['phone','Телефон'],['messenger','Чат'],['social','Соцсети']];
const values=(v)=>[...new Set(String(v||'').split(/[;\n]+/).map(x=>x.trim()).filter(x=>x&&x.toLowerCase()!=='данных нет'))];
const channels=computed(()=>labels.map(([key,label])=>({key,label,values:values(props.contact[key])})));
function link(kind,value){
 if(kind==='email'&&/^[^\s@,;?&#]+@[^\s@,;?&#]+\.[^\s@,;?&#]+$/.test(value))return 'mailto:'+value;
 if(kind==='phone'&&/^[+\d\s().-]+$/.test(value)){const p=value.replace(/[^+\d]/g,'');if(/^\+?\d{9,15}$/.test(p))return 'tel:'+p;}
 const t=value.replace(/^Telegram\s*/i,'');
 if(kind==='messenger'&&/^@[a-zA-Z0-9_]{3,}$/.test(t))return 'https://t.me/'+t.slice(1);
 if(kind==='messenger'||kind==='social'){try{const u=new URL(t);if(u.protocol==='https:'&&!u.username&&!u.password)return u.href;}catch{}}
 return null;
}
function toggle(k){active.value=active.value===k?'':k;copied.value='';error.value='';}
async function copy(v,k){error.value='';copied.value='';try{if(!navigator.clipboard?.writeText)throw new Error();await navigator.clipboard.writeText(v);copied.value=k;}catch{error.value='Не удалось скопировать автоматически. Выделите значение вручную.';}}
</script>
<template>
<div class="hub-channels">
 <div v-if="!expanded" class="channel-chips">
  <template v-for="c in channels" :key="c.key">
   <button v-if="c.values.length" type="button" class="channel-chip available" :aria-expanded="active===c.key" :title="'Показать и скопировать: '+c.label" @click="toggle(c.key)"><span aria-hidden="true">●</span> {{c.label}}<small v-if="c.values.length>1">{{c.values.length}}</small></button>
   <span v-else class="channel-chip unavailable" :title="c.label+': нет данных'">{{c.label}}</span>
  </template>
 </div>
 <div v-for="c in channels.filter(c=>expanded||active===c.key)" :key="c.key" class="channel-values">
  <strong>{{c.label}}</strong><span v-if="!c.values.length" class="muted"> — нет данных</span>
  <div v-for="(value,i) in c.values" :key="value" class="channel-value">
   <span class="channel-text">{{value}}</span>
   <div class="channel-actions"><button type="button" class="copy-button" @click="copy(value,c.key+':'+i)">{{copied===c.key+':'+i?'Скопировано ✓':'Копировать'}}</button><a v-if="link(c.key,value)" :href="link(c.key,value)" :target="['messenger','social'].includes(c.key)?'_blank':undefined" rel="noopener noreferrer">{{c.key==='email'?'Написать':c.key==='phone'?'Позвонить':'Открыть ↗'}}</a></div>
  </div>
  <button v-if="c.values.length>1" type="button" class="copy-button" @click="copy(c.values.join('; '),c.key+':all')">{{copied===c.key+':all'?'Скопировано ✓':'Копировать все'}}</button>
 </div>
 <p v-if="error" class="error" role="alert">{{error}}</p>
 <span v-if="copied" class="sr-only" role="status">Скопировано в буфер обмена</span>
</div>
</template>
