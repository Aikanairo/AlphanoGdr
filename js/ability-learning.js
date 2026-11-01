/* Availability comes from rpg_sheet; the client never reconstructs racial or XP rules. */
export function isLearnableAbility(ability){return Boolean(ability?.learnable??(ability?.category==='purchasable'));}
export function canLearnAbility(ability,canEdit=true){
 return Boolean(canEdit&&isLearnableAbility(ability)&&!ability.owned&&ability.available);
}
export function renderLearningBalance(target,sheet){
 if(!target)return;
 target.textContent=Number(sheet.xp.available||0).toLocaleString('it-IT')+' PE spendibili in abilità';
 target.title='Saldo condiviso con l’allenamento delle caratteristiche. Ogni acquisto aggiorna i PE disponibili.';
}
