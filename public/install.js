const choice=document.querySelector('#db-driver');
const fields=document.querySelector('#mysql-fields');
function update(){if(!choice||!fields)return;fields.hidden=choice.value!=='mysql';fields.querySelectorAll('input').forEach(input=>{if(input.name!=='db_prefix')input.disabled=choice.value!=='mysql';});}
choice?.addEventListener('change',update);update();
document.querySelector('form')?.addEventListener('submit',()=>{const button=document.querySelector('button[type=submit]');button.disabled=true;button.querySelector('span').textContent='Устанавливаем…';});
