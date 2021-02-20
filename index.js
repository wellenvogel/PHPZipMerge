(function(){
    let items=['outname','zipfile','filename','file'];
    window.addEventListener('load',function(){
        let bt=document.getElementById('download');
        let fel=document.getElementById('downloadframe');
        let markEmpty=function(){
            items.forEach(function(item) {
                let iel = document.getElementById(item);
                let v = iel.value;
                if (!v) {
                    iel.classList.add('empty');
                } else {
                    iel.classList.remove('empty');
                }
            });
        }
        bt.addEventListener('click',function(){
            let url='proxy.php?';
            for (let i in items){
                let item=items[i];
                let iel=document.getElementById(item);
                let v=iel.value;
                if (! v){
                    alert(item+ "cannot be empty");
                    return;
                }
                url+="&"+item+"="+encodeURIComponent(v);
            };
            fel.setAttribute('src',url);
        });
        fel.addEventListener('load',function(ev){
            alert("Error: "+ev.target.contentDocument.body.textContent);
        })
        window.setInterval(markEmpty,1000);
    });
})();