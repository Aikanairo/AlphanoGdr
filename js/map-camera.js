/* Camera in source-image coordinates. One source pixel equals one CSS pixel at 100%. */
(function(root){
 'use strict';
 class MapCamera {
  constructor(width,height){this.width=width;this.height=height;this.x=width/2;this.y=height/2;this.scale=1;this.mode='native';}
  resize(width,height,native=false){this.vw=Math.max(1,width);this.vh=Math.max(1,height);this.fit=Math.min(this.vw/this.width,this.vh/this.height);this.fill=Math.max(this.vw/this.width,this.vh/this.height);if(native)this.mode='native';if(this.mode==='native')this.scale=Math.max(this.fit,1);if(this.mode==='overview')this.scale=this.fit;if(this.mode==='cover')this.scale=this.fill;this.clamp();return this;}
  clamp(){this.scale=Math.max(this.fit,Math.min(Math.max(2,this.fill),this.scale));const w=this.vw/this.scale,h=this.vh/this.scale;this.x=w>=this.width?this.width/2:Math.max(w/2,Math.min(this.width-w/2,this.x));this.y=h>=this.height?this.height/2:Math.max(h/2,Math.min(this.height-h/2,this.y));}
  view(){return {x:this.x-this.vw/this.scale/2,y:this.y-this.vh/this.scale/2,width:this.vw/this.scale,height:this.vh/this.scale};}
  world(x,y){const v=this.view();return{x:v.x+x/this.scale,y:v.y+y/this.scale};}
  zoom(scale,x=this.vw/2,y=this.vh/2){const anchor=this.world(x,y);this.mode='manual';this.scale=scale;this.clamp();this.x=anchor.x+(this.vw/2-x)/this.scale;this.y=anchor.y+(this.vh/2-y)/this.scale;this.clamp();}
  pan(dx,dy){this.x-=dx/this.scale;this.y-=dy/this.scale;this.clamp();}
  cover(){this.mode='cover';this.scale=this.fill;this.x=this.width/2;this.y=this.height/2;this.clamp();}
  overview(){this.mode='overview';this.scale=this.fit;this.x=this.width/2;this.y=this.height/2;this.clamp();}
  native(){this.mode='native';this.scale=Math.max(this.fit,1);this.clamp();}
 }
 if(typeof module!=='undefined'&&module.exports)module.exports=MapCamera;else root.AlphanoMapCamera=MapCamera;
})(typeof window!=='undefined'?window:this);
