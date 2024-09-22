(()=>{"use strict";var e,a={650:(e,a,o)=>{const l=window.wp.blocks,t=window.wp.element,n=window.wp.i18n,s=window.wp.blockEditor,c=window.wp.serverSideRender;var i=o.n(c);const r=window.wp.components;function b({adminUrl:e}){return(0,t.createElement)("fieldset",{className:"components-placeholder__fieldset"},(0,t.createElement)("div",{className:"esf-fb-no-pages"},(0,t.createElement)("span",{className:"block-editor-block-card__description"},(0,n.__)("No account found, please connect the Facebook account using the following button","easy-facebook-likebox")),(0,t.createElement)("div",null,(0,t.createElement)(r.Button,{isPrimary:!0,target:"_blank",href:`${e}admin.php?page=easy-facebook-likebox`},(0,n.__)("Connect","easy-facebook-likebox")))))}const k=JSON.parse('{"u2":"esf-fb/halfwidth"}');(0,l.registerBlockType)(k.u2,{edit:function({attributes:e,setAttributes:a}){const{fanpage_id:o,filter:l,album_id:c}=e,k=esfFBBlockData.adminUrl,[_,u]=(0,t.useState)(o),[f,m]=(0,t.useState)(c),[p,d]=(0,t.useState)(!1),[y,h]=(0,t.useState)([]);(0,t.useEffect)((()=>{(async()=>{if(_)try{const e=await fetch(esfFBBlockData.ajax_url,{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:new URLSearchParams({action:"efbl_get_albums_list",page_id:_,efbl_nonce:esfFBBlockData.nonce,is_block:!0})}),{success:a,data:o}=await e.json();a&&Array.isArray(o)?h(o.map((e=>({label:e.name,value:e.id})))):console.error("Error retrieving album list")}catch(e){console.error(e)}else h([])})()}),[_]);const v=e=>{m(e),d(!0),a("none"===e?{album_id:""}:{album_id:e})},E=()=>{u(null),a({fanpage_id:null,filter:"",album_id:""})},g=esfFBBlockData&&esfFBBlockData.pages&&"object"==typeof esfFBBlockData.pages?Object.entries(esfFBBlockData.pages).map((([e,a])=>({label:a.name,value:e}))):[];if(o){const c=esfFBBlockData.pages[o].name;return(0,t.createElement)("div",(0,s.useBlockProps)(),(0,t.createElement)(s.InspectorControls,null,(0,t.createElement)(r.PanelBody,{title:(0,n.__)("Account Settings","easy-facebook-likebox"),initialOpen:!0},(0,t.createElement)(r.PanelRow,null,(0,t.createElement)("span",null,(0,n.__)("Account","easy-facebook-likebox")),(0,t.createElement)("span",null,c)),(0,t.createElement)(r.PanelRow,null,(0,t.createElement)(r.Button,{isSecondary:!0,onClick:E},(0,n.__)("Reset","easy-facebook-likebox")))),(0,t.createElement)(r.PanelBody,{title:(0,n.__)("Display Settings","easy-facebook-likebox"),initialOpen:!0},(0,t.createElement)(r.SelectControl,{label:(0,n.__)("Filter","easy-facebook-likebox"),value:l,options:[{label:(0,n.__)("None","easy-facebook-likebox"),value:""},{label:(0,n.__)("Images","easy-facebook-likebox"),value:"images"},{label:(0,n.__)("Videos","easy-facebook-likebox"),value:"videos"},{label:(0,n.__)("Events","easy-facebook-likebox"),value:"events"},{label:(0,n.__)("Albums","easy-facebook-likebox"),value:"albums"},{label:(0,n.__)("Mentioned","easy-facebook-likebox"),value:"mentioned"}],onChange:e=>{a({filter:e})}}),"events"===l&&(0,t.createElement)("div",null,(0,t.createElement)(r.Notice,{status:"info",isDismissible:!1},(0,t.createElement)("p",null,(0,n.__)("Due to recent changes by Facebook in the API. You need to create your own application in order to display events on your site.","easy-facebook-likebox")),(0,t.createElement)("p",null,(0,t.createElement)("a",{href:"https://easysocialfeed.com/custom-facebook-feed/page-token/",target:"_blank",rel:"noopener noreferrer"},(0,n.__)("Please follow the steps in this guide","easy-facebook-likebox"))),(0,t.createElement)("p",null,(0,n.__)(" to create the application. Note: It is required only to display events.","easy-facebook-likebox"))),(0,t.createElement)(r.SelectControl,{label:(0,n.__)("Events Filter","easy-facebook-likebox"),value:e.events_filter,options:[{label:(0,n.__)("Upcoming","easy-facebook-likebox"),value:"upcoming"},{label:(0,n.__)("Past","easy-facebook-likebox"),value:"past"},{label:(0,n.__)("All","easy-facebook-likebox"),value:"all"}],onChange:e=>{a({events_filter:e})}})),"albums"===l&&(0,t.createElement)(r.SelectControl,{label:(0,n.__)("Album","easy-facebook-likebox"),value:f,options:[{label:(0,n.__)("None","easy-facebook-likebox"),value:"none"},...y],onChange:v}),(0,t.createElement)(r.TextControl,{label:(0,n.__)("Words Limit","easy-facebook-likebox"),type:"number",min:"1",value:e.words_limit||"",onChange:e=>a({words_limit:parseInt(e,10)})}),(0,t.createElement)(r.ToggleControl,{label:(0,n.__)("Open links in new tab","easy-facebook-likebox"),checked:e.links_new_tab,onChange:e=>a({links_new_tab:e?1:0})}),(0,t.createElement)(r.TextControl,{label:(0,n.__)("Post Limit","easy-facebook-likebox"),type:"number",min:"1",value:e.post_limit||"",onChange:e=>a({post_limit:parseInt(e,10)})}),(0,t.createElement)(r.TextControl,{label:(0,n.__)("Cache Unit","easy-facebook-likebox"),type:"number",min:"1",value:e.cache_unit||"",onChange:e=>a({cache_unit:parseInt(e,10)})}),(0,t.createElement)(r.SelectControl,{label:(0,n.__)("Cache Duration","easy-facebook-likebox"),value:e.cache_duration,options:[{value:"minutes",label:(0,n.__)("Minutes","easy-facebook-likebox")},{value:"hours",label:(0,n.__)("Hours","easy-facebook-likebox")},{value:"days",label:(0,n.__)("Days","easy-facebook-likebox")}],onChange:e=>a({cache_duration:e})}),(0,t.createElement)(r.ToggleControl,{label:(0,n.__)("Show Likebox","easy-facebook-likebox"),checked:e.show_like_box,onChange:e=>a({show_like_box:e?1:0})}),(0,t.createElement)(r.PanelRow,null,(0,t.createElement)("h2",null,(0,n.__)("Upgrade to unlock following blocks","easy-facebook-likebox"))),(0,t.createElement)(r.PanelRow,null,(0,t.createElement)("ol",null,(0,t.createElement)("li",null,(0,n.__)("Carousel","easy-facebook-likebox")),(0,t.createElement)("li",null,(0,n.__)("Masonry","easy-facebook-likebox")),(0,t.createElement)("li",null,(0,n.__)("Grid","easy-facebook-likebox")))),(0,t.createElement)(r.PanelRow,null,(0,t.createElement)("a",{href:"/wp-admin/admin.php?page=feed-them-all-pricing",className:"button button-primary",target:"_blank",rel:"noopener noreferrer"},(0,n.__)("Upgrade Now","easy-facebook-likebox"))))),(0,t.createElement)(i(),{block:"esf-fb/halfwidth",attributes:e,EmptyResponsePlaceholder:()=>(0,t.createElement)(r.Spinner,null)}))}return(0,t.createElement)("div",(0,s.useBlockProps)(),(0,t.createElement)("div",{className:"components-placeholder is-large"},(0,t.createElement)("div",{className:"components-placeholder__label esf-fb-block-header"},(0,t.createElement)("span",{className:"dashicon dashicons dashicons-facebook"}),(0,n.__)("ESF Facebook Halfwidth","easy-facebook-likebox")),esfFBBlockData&&esfFBBlockData.pages?(0,t.createElement)("fieldset",{className:"components-placeholder__fieldset"},(0,t.createElement)("div",{className:"esf-fb-select-pages"},(0,t.createElement)(r.RadioControl,{label:(0,n.__)("Select Page","easy-facebook-likebox"),selected:_,options:g,onChange:e=>{u(e)}}),(0,t.createElement)("div",{className:"esf-block-btns"},(0,t.createElement)(r.Button,{isPrimary:!0,onClick:()=>{a({fanpage_id:_,album_id:f}),d(!1)}},(0,n.__)("Display","easy-facebook-likebox")),(0,t.createElement)(r.Button,{isSecondary:!0,onClick:E},(0,n.__)("Reset","easy-facebook-likebox"))))):(0,t.createElement)(b,{adminUrl:k})))},save:function(){return(0,t.createElement)("p",s.useBlockProps.save(),"ESF Block – Content is being loaded from server side")}})}},o={};function l(e){var t=o[e];if(void 0!==t)return t.exports;var n=o[e]={exports:{}};return a[e](n,n.exports,l),n.exports}l.m=a,e=[],l.O=(a,o,t,n)=>{if(!o){var s=1/0;for(b=0;b<e.length;b++){for(var[o,t,n]=e[b],c=!0,i=0;i<o.length;i++)(!1&n||s>=n)&&Object.keys(l.O).every((e=>l.O[e](o[i])))?o.splice(i--,1):(c=!1,n<s&&(s=n));if(c){e.splice(b--,1);var r=t();void 0!==r&&(a=r)}}return a}n=n||0;for(var b=e.length;b>0&&e[b-1][2]>n;b--)e[b]=e[b-1];e[b]=[o,t,n]},l.n=e=>{var a=e&&e.__esModule?()=>e.default:()=>e;return l.d(a,{a}),a},l.d=(e,a)=>{for(var o in a)l.o(a,o)&&!l.o(e,o)&&Object.defineProperty(e,o,{enumerable:!0,get:a[o]})},l.o=(e,a)=>Object.prototype.hasOwnProperty.call(e,a),(()=>{var e={872:0,630:0};l.O.j=a=>0===e[a];var a=(a,o)=>{var t,n,[s,c,i]=o,r=0;if(s.some((a=>0!==e[a]))){for(t in c)l.o(c,t)&&(l.m[t]=c[t]);if(i)var b=i(l)}for(a&&a(o);r<s.length;r++)n=s[r],l.o(e,n)&&e[n]&&e[n][0](),e[n]=0;return l.O(b)},o=globalThis.webpackChunkeasy_social_feed_facebook_carousel=globalThis.webpackChunkeasy_social_feed_facebook_carousel||[];o.forEach(a.bind(null,0)),o.push=a.bind(null,o.push.bind(o))})();var t=l.O(void 0,[630],(()=>l(650)));t=l.O(t)})();