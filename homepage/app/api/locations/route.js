export async function GET() {
 try {
  const response=await fetch(`${process.env.LARAVEL_API_URL || 'http://127.0.0.1:8000'}/api/locations`,{cache:'no-store',headers:{Accept:'application/json'},signal:AbortSignal.timeout(8000)});
  if (response.status >= 500) throw new Error('Unavailable');
  return Response.json(await response.json(),{status:response.status,headers:{'Cache-Control':'no-store'}});
 } catch { return Response.json({message:'Delivery locations are temporarily unavailable.'},{status:503,headers:{'Cache-Control':'no-store','Retry-After':'10'}}); }
}
