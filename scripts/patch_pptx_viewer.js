/**
 * Re-apply the local modifications to Assets/js/vendor/pptx-viewer.umd.js.
 *
 * ⚠️  THIS SCRIPT NO LONGER RUNS, BY DESIGN.
 * The bundle is first-party (MIT, see NOTICE) and is committed in ALREADY-PATCHED
 * form, so the pristine snippets this script searches for are no longer present.
 * Running it is a no-op that now exits non-zero rather than pretending to succeed.
 *
 * The right fix is to fold these six changes into the bundle's own source and
 * rebuild, rather than patching a build artifact after the fact — at which point
 * this file can be deleted outright.
 *
 * Until then it is kept for the record of WHAT was changed and why —
 * the six edits below are the plugin's PPTX rendering fixes (whitespace
 * preservation, paragraph child ordering and manual <br>, run breaks, paragraph
 * alignment, shape flipH/flipV, and the SVG transform for those flips).
 *
 * It now exits non-zero when a target snippet is missing. Previously every
 * failure printed "orig not found!" and the script still ended with
 * "ALL PPTX FIXES APPLIED SUCCESSFULLY!" and exit code 0 — so a run that changed
 * nothing at all was indistinguishable from a successful one, in CI included.
 *
 * NOTE on the `.replace()` calls: each replacement is passed as a callback
 * `() => replacement` rather than a string. The minified bundle contains `$&`
 * and `$1`-style sequences, which a string replacement would expand as capture
 * references and inject syntax errors into the output.
 */
const fs = require("fs");
const path = require("path");

const file = path.resolve(__dirname, "../Assets/js/vendor/pptx-viewer.umd.js");
let code = fs.readFileSync(file, "utf8");

const failures = [];

// 1. Fix W(e) whitespace trimming
const wOrig = "function W(e){var t;return((t=e.textContent)==null?void 0:t.trim())||\"\"}";
const wNew = "function W(e){var t;return((t=e.textContent)==null?void 0:t)||\"\"}";
if (!code.includes(wOrig)) {
  failures.push("W");
} else {
  code = code.replace(wOrig, () => wNew);
  console.log("W fixed");
}

// 2. Fix kn paragraph parsing to preserve child order and manual br
const knOrig = "function kn(e,t,n){const r=[],s=h(e,\"pPr\"),i=Ln(s),l=Tn(s),c=le(s,\"spcBef\"),o=le(s,\"spcAft\"),a=Mn(s,t),u=C(s||e,\"lvl\",0),{marginLeft:d,indent:f}=Rn(s),p=R(e,\"r\");for(const g of p){const $=Pn(g,t,n);r.push($)}R(e,\"br\").length>0&&r.length===0&&r.push({text:\"\"});const b=R(e,\"fld\");for(const g of b){const $=h(g,\"t\");$&&r.push({text:W($)})}return{runs:r,align:i,lineSpacing:l,spaceBefore:c,spaceAfter:o,bullet:a,level:u,marginLeft:d,indent:f}}";
const knNew = "function kn(e,t,n){const r=[],s=h(e,\"pPr\"),i=Ln(s),l=Tn(s),c=le(s,\"spcBef\"),o=le(s,\"spcAft\"),a=Mn(s,t),u=C(s||e,\"lvl\",0),{marginLeft:d,indent:f}=Rn(s);for(const g of Array.from(e.children)){const k=Pt(g);if(k===\"r\"){r.push(Pn(g,t,n))}else if(k===\"br\"){r.push({text:\"\\n\",isBreak:!0})}else if(k===\"fld\"){const $=h(g,\"t\");$&&r.push({text:W($)})}}return{runs:r,align:i,lineSpacing:l,spaceBefore:c,spaceAfter:o,bullet:a,level:u,marginLeft:d,indent:f}}";
if (!code.includes(knOrig)) {
  failures.push("kn");
} else {
  code = code.replace(knOrig, () => knNew);
  console.log("kn fixed");
}

// 3. Fix Hr to handle isBreak
const hrOrig = "function Hr(e,t){const n=document.createElement(\"span\");if(n.textContent=e.text,";
const hrNew = "function Hr(e,t){if(e.isBreak||e.text===\"\\n\")return document.createElement(\"br\");const n=document.createElement(\"span\");if(n.textContent=e.text,";
if (!code.includes(hrOrig)) {
  failures.push("hr");
} else {
  code = code.replace(hrOrig, () => hrNew);
  console.log("Hr fixed");
}

// 4. Fix Xr paragraph layout & alignment
const xrOrig = "function Xr(e,t,n){const r=document.createElement(\"p\");switch(r.style.margin=\"0\",r.style.padding=\"0\",r.style.display=\"flex\",r.style.alignItems=\"baseline\",e.align){case\"center\":r.style.justifyContent=\"center\";break;case\"right\":r.style.justifyContent=\"flex-end\";break;default:r.style.justifyContent=\"flex-start\"}if(e.lineSpacing){const o=e.lineSpacing*(1-n.lineSpacingReduction);r.style.lineHeight=String(Math.max(o,.8))}e.spaceBefore&&(r.style.marginTop=`${e.spaceBefore}px`),e.spaceAfter&&(r.style.marginBottom=`${e.spaceAfter}px`);const s=e.level||0;let i=e.marginLeft??s*36;const l=e.indent??(e.bullet?-18:0);if(i>0&&(r.style.marginLeft=`${i}px`),e.bullet){const o=document.createElement(\"span\");o.style.flexShrink=\"0\",o.style.display=\"inline-block\";const a=Math.abs(l)||18;if(o.style.width=`${a}px`,o.style.marginLeft=l<0?`${l}px`:\"0\",o.style.textAlign=\"left\",e.bullet.font&&(o.style.fontFamily=`\"${e.bullet.font}\", sans-serif`),e.bullet.color&&(o.style.color=M(e.bullet.color)),e.bullet.sizePercent&&(o.style.fontSize=`${e.bullet.sizePercent}%`),e.bullet.type===\"bullet\")o.textContent=e.bullet.char||\"•\",t.numbers.delete(s);else{const u=`${s}-${e.bullet.numberType||\"arabicPeriod\"}`;t.lastBulletType.get(s)!==u&&(t.numbers.set(s,e.bullet.startAt||1),t.lastBulletType.set(s,u));const f=t.numbers.get(s)||e.bullet.startAt||1;o.textContent=Ur(f,e.bullet.numberType),t.numbers.set(s,f+1);for(const[p]of t.numbers)p>s&&(t.numbers.delete(p),t.lastBulletType.delete(p))}r.appendChild(o)}const c=document.createElement(\"span\");c.style.flex=\"1\",c.style.minWidth=\"0\";for(const o of e.runs){const a=Hr(o,n);c.appendChild(a)}return e.runs.length===0&&!e.bullet&&(c.innerHTML=\"&nbsp;\"),r.appendChild(c),r}";
const xrNew = "function Xr(e,t,n){const r=document.createElement(\"p\");r.style.margin=\"0\",r.style.padding=\"0\",r.style.textAlign=e.align||\"left\";if(e.bullet){r.style.display=\"flex\",r.style.alignItems=\"baseline\";switch(e.align){case\"center\":r.style.justifyContent=\"center\";break;case\"right\":r.style.justifyContent=\"flex-end\";break;default:r.style.justifyContent=\"flex-start\"}}if(e.lineSpacing){const o=e.lineSpacing*(1-n.lineSpacingReduction);r.style.lineHeight=String(Math.max(o,.8))}e.spaceBefore&&(r.style.marginTop=`${e.spaceBefore}px`),e.spaceAfter&&(r.style.marginBottom=`${e.spaceAfter}px`);const s=e.level||0;let i=e.marginLeft??s*36;const l=e.indent??(e.bullet?-18:0);if(i>0&&(r.style.marginLeft=`${i}px`),e.bullet){const o=document.createElement(\"span\");o.style.flexShrink=\"0\",o.style.display=\"inline-block\";const a=Math.abs(l)||18;if(o.style.width=`${a}px`,o.style.marginLeft=l<0?`${l}px`:\"0\",o.style.textAlign=\"left\",e.bullet.font&&(o.style.fontFamily=`\"${e.bullet.font}\", sans-serif`),e.bullet.color&&(o.style.color=M(e.bullet.color)),e.bullet.sizePercent&&(o.style.fontSize=`${e.bullet.sizePercent}%`),e.bullet.type===\"bullet\")o.textContent=e.bullet.char||\"•\",t.numbers.delete(s);else{const u=`${s}-${e.bullet.numberType||\"arabicPeriod\"}`;t.lastBulletType.get(s)!==u&&(t.numbers.set(s,e.bullet.startAt||1),t.lastBulletType.set(s,u));const f=t.numbers.get(s)||e.bullet.startAt||1;o.textContent=Ur(f,e.bullet.numberType),t.numbers.set(s,f+1);for(const[p]of t.numbers)p>s&&(t.numbers.delete(p),t.lastBulletType.delete(p))}r.appendChild(o)}const c=document.createElement(\"span\");c.style.flex=\"1\",c.style.minWidth=\"0\",c.style.textAlign=e.align||\"left\";for(const o of e.runs){const a=Hr(o,n);c.appendChild(a)}return e.runs.length===0&&!e.bullet&&(c.innerHTML=\"&nbsp;\"),r.appendChild(c),r}";
if (!code.includes(xrOrig)) {
  failures.push("xr");
} else {
  code = code.replace(xrOrig, () => xrNew);
  console.log("Xr fixed");
}

// 5. Fix Zn flipH/flipV
const znOrig = "const a=Ct(l),u=ir(l),d=fe(l),f=lr(l,t.themeColors),p=pe(l,t.themeColors),A=ge(l,t.themeColors),b=h(e,\"txBody\"),g=b?ie(b,t.themeColors,t.relationships):void 0;if(g&&i){if(o){const F=o.text;F&&fr(g,F)}(w=t.master)!=null&&w.textStyles&&dr(g,i,t.master.textStyles)}const $=f&&f.type!==\"none\",x=p&&p.width>0;return u===\"rect\"&&!$&&!x&&g?{id:s,type:\"text\",bounds:c,rotation:a,text:g,placeholder:i,shadow:A}:{id:s,type:\"shape\",bounds:c,rotation:a,shapeType:u,fill:f,stroke:p,shadow:A,text:g,placeholder:i,adjustments:d}";
const znNew = "const a=Ct(l),u=ir(l),d=fe(l),f=lr(l,t.themeColors),p=pe(l,t.themeColors),A=ge(l,t.themeColors),_xf=h(l,\"xfrm\"),_flH=_xf?q(_xf,\"flipH\"):!1,_flV=_xf?q(_xf,\"flipV\"):!1,b=h(e,\"txBody\"),g=b?ie(b,t.themeColors,t.relationships):void 0;if(g&&i){if(o){const F=o.text;F&&fr(g,F)}(w=t.master)!=null&&w.textStyles&&dr(g,i,t.master.textStyles)}const $=f&&f.type!==\"none\",x=p&&p.width>0;return u===\"rect\"&&!$&&!x&&g?{id:s,type:\"text\",bounds:c,rotation:a,text:g,placeholder:i,shadow:A,flipH:_flH||void 0,flipV:_flV||void 0}:{id:s,type:\"shape\",bounds:c,rotation:a,shapeType:u,fill:f,stroke:p,shadow:A,text:g,placeholder:i,adjustments:d,flipH:_flH||void 0,flipV:_flV||void 0}";
if (!code.includes(znOrig)) {
  failures.push("zn");
} else {
  code = code.replace(znOrig, () => znNew);
  console.log("Zn fixed");
}

// 6. Fix so transform for flipH and flipV
const soOrig = "function so(e,t){const n=[];if(n.push(`translate(${e.x}, ${e.y})`),t){const r=e.width/2,s=e.height/2;n.push(`rotate(${t}, ${r}, ${s})`)}return n.join(\" \")}";
const soNew = "function so(e,t){const n=[];if(n.push(`translate(${e.x}, ${e.y})`),t){const r=e.width/2,s=e.height/2;n.push(`rotate(${t}, ${r}, ${s})`)}if(e.flipH){const r=e.width/2;n.push(`translate(${r}, 0) scale(-1, 1) translate(-${r}, 0)`)}if(e.flipV){const s=e.height/2;n.push(`translate(0, ${s}) scale(1, -1) translate(0, -${s})`)}return n.join(\" \")}";
if (!code.includes(soOrig)) {
  failures.push("so");
} else {
  code = code.replace(soOrig, () => soNew);
  console.log("so fixed");
}

if (failures.length > 0) {
  console.error(
    `\n✖ ${failures.length} of 6 patches did not match: ${failures.join(", ")}.\n` +
    "  The bundle in Assets/js/vendor/ is committed already-patched, so this is\n" +
    "  expected on a clean checkout. Nothing has been written.\n" +
    "  See the header of this file and NOTICE."
  );
  process.exit(1);
}

fs.writeFileSync(file, code, "utf8");
console.log("✔ All 6 PPTX patches applied.");
