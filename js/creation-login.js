import {creationApi,openCharacterCreation} from './character-creation.js';
async function check(){try{const sheet=await creationApi('profile');if(!sheet.creation?.needs_points&&!sheet.creation?.needs_innates)return;const open=()=>{if(document.querySelector('dialog[open]')){setTimeout(open,1000);return;}openCharacterCreation(sheet).catch(console.error);};open();}catch(e){console.error('Creazione personaggio:',e);}}
check();
