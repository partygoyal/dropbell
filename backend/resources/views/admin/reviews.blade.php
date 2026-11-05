@extends('admin.layout')
@section('title','Review moderation')
@section('content')
<h1>Verified-purchase reviews</h1><p>Only published reviews contribute to public ratings. Edited reviews return to moderation.</p>
<div class="table-wrap"><table><thead><tr><th>Product</th><th>Rating</th><th>Review</th><th>Status</th><th>Actions</th></tr></thead><tbody>@forelse($reviews as $review)<tr><td>{{ $review->product_name??'Deleted product' }}</td><td>{{ $review->rating }}/5</td><td>{{ $review->comment }}</td><td>{{ $review->status }}</td><td><form method="post" action="{{ route('admin.reviews.update',$review->id) }}">@csrf @method('PATCH')<input type="hidden" name="revision" value="{{ $review->revision }}"><button name="status" value="published">Publish</button><button name="status" value="rejected">Hide</button></form></td></tr>@empty<tr><td colspan="5">No reviews to moderate.</td></tr>@endforelse</tbody></table></div>{{ $reviews->links() }}
@endsection
