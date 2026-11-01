/* Run the actual camera code against changing viewport geometry, without a browser dependency. */
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
class Element{
 constructor(){this.style={setProperty(){}};this.events={};this.dataset={};this.classList={add(){},remove(){},toggle(){}};this.children=[];}
 addEventListener(name,fn){this.events[name]=fn;} append(child){this.children.push(child);} add(child){this.children.push(child);} replaceChildren(...children){this.children=children;} setAttribute(){} focus(){} querySelector(){return null;} querySelectorAll(){return [];} closest(){return null;}
}
let size={width:2048,height:1100},resize;
const root=new Element(),viewport=new Element(),canvas=new Element(),image=new Element(),layer=new Element(),jump=new Element(),status=new Element(),output=new Element();
const points=[{id:1,title:'City AlphanoGDR',x:53.83672,y:49.30645,placed:true,style:'label',url:'/mappa.php?area=1'}];
const config={editor:false,image:'/assets/maps/world.png',markers:points,destinations:[]};
viewport.getBoundingClientRect=()=>({...size,left:0,top:0});image.naturalWidth=1920;image.naturalHeight=1080;
Object.defineProperty(image,'src',{set(){image.events.load();}});
const elements={'[data-atlas-config]':{textContent:JSON.stringify(config)},'[data-atlas-viewport]':viewport,'[data-atlas-canvas]':canvas,'[data-atlas-image]':image,'[data-atlas-markers]':layer,'[data-atlas-jump]':jump,'[data-atlas-status]':status,'[data-atlas-zoom]':output};
root.querySelector=key=>elements[key];const buttons=['in','out','cover','fit'].map(action=>{const b=new Element();b.dataset.atlasAction=action;return b;});root.querySelectorAll=key=>key==='[data-atlas-action]'?buttons:[];
const document={querySelectorAll:()=>[root],createElement:()=>new Element()};
vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname,'../assets/atlas.js'),'utf8'),{document,Option:function(text,value){this.text=text;this.value=value;},ResizeObserver:class{constructor(fn){resize=fn;}observe(){}},Map,JSON,Math,Number,URL,location:{assign(){}}});
let checks=0;
function covered(){
 const [x,y,scale]=canvas.style.transform.match(/translate\(([-\d.e]+)px,([-\d.e]+)px\) scale\(([-\d.e]+)\)/).slice(1).map(Number),epsilon=.001;
 assert(x<=epsilon&&y<=epsilon&&x+1920*scale>=size.width-epsilon&&y+1080*scale>=size.height-epsilon,'image must cover all four viewport edges');
 assert.equal(layer.children[0].style.left,'53.83672%');assert.equal(layer.children[0].style.top,'49.30645%');checks++;
}
covered();
for(const geometry of [{width:390,height:844},{width:2560,height:1440},{width:1000,height:1200},{width:1200,height:500}]){
 size=geometry;resize();covered();
 for(let i=0;i<20;i++)buttons[1].events.click();covered();
 buttons[0].events.click();covered();buttons[2].events.click();covered();
 // Even a stale public fit action cannot expose the background.
 buttons[3].events.click();covered();
}
console.log('PASS atlas camera: '+checks+' coverage and fixed-marker checks across resize and zoom');
