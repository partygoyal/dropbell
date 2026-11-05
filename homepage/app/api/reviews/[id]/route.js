export async function GET(request, { params }) {
 const {id}=await params;if(!/^[1-9]\d*$/.test(id))return Response.json({message:'Not found.'},{status:404});
 const page=new URL(request.url).searchParams.get('page')||'1';
 try {const r=await fetch(`${process.env.LARAVEL_API_URL||'http://127.0.0.1:8000'}/api/products/${id}/reviews?page=${/^[1-9]\d*$/.test(page)?page:'1'}`,{cache:'no-store',signal:AbortSignal.timeout(8000),headers:{Accept:'application/json'}});if(!r.ok)throw Error();return Response.json(await r.json(),{headers:{'Cache-Control':'no-store'}});}catch{return Response.json({message:'Reviews are temporarily unavailable.'},{status:503});}
}
