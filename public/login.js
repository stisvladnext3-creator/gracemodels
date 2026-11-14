const form=document.querySelector('#login-form');
const password=document.querySelector('#password');
const show=document.querySelector('#show-password');
show.addEventListener('click',()=>{
  const visible=password.type==='password';
  password.type=visible?'text':'password';
  show.textContent=visible?'Скрыть':'Показать';
  show.setAttribute('aria-label',visible?'Скрыть пароль':'Показать пароль');
  show.setAttribute('aria-pressed',String(visible));
});
form.addEventListener('submit',async event=>{
  event.preventDefault();
  const button=form.querySelector('button[type=submit]');
  const label=button.querySelector('span');
  const error=document.querySelector('#login-error');
  error.textContent='';button.disabled=true;label.textContent='Проверяем…';
  try{
    const response=await fetch('/api/studio/login',{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify({login:form.elements.login.value,password:password.value})});
    const result=await response.json();
    if(!response.ok)throw new Error(result.error||'Не удалось войти. Попробуйте снова.');
    password.value='';
    location.replace(location.pathname);
  }catch(failure){
    error.textContent=failure.message==='Failed to fetch'?'Нет связи с сервером. Проверьте соединение и попробуйте снова.':failure.message;
    button.disabled=false;label.textContent='Войти в студию';
  }
});
