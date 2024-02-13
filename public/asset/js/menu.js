let menu = document.querySelector(".menu");
let open = document.querySelector(".open");
open.addEventListener('click', ()=>{
    openNav();
});
function openNav() {
  menu.classList.toggle("active");
  open.classList.toggle("close");
 
}

