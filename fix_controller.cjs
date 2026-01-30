const fs = require('fs');

const content = fs.readFileSync('app/Http/Controllers/Api/ProgressController.php', 'utf8');

const oldMethod = `/**
     * Get attachment file for a progress entry.
     */
    public function getAttachment($progressId)
    {
        $attachment = ProgressAttachment::where('progress_id', $progressId)->first();

        if (!$attachment) {
            return response()->json(['message' => 'Attachment not found'], 404);
        }`;

const newMethod = `/**
     * Get attachment file for a progress entry.
     * Supports both Authorization header and query parameter for token.
     */
    public function getAttachment(Request $request, $progressId)
    {
        // For file downloads, we need to handle token from query param
        // since window.open doesn't send headers/cookies
        $token = $request->get('token');

        if ($token) {
            // Validate token manually
            $user = \App\Models\User::where('id', \Laravel\Sanctum\PersonalAccessToken::findToken($token)?->tokenable_id)->first();
            if (!$user) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }
            $request->setUserResolver(function () use ($user) {
                return $user;
            });
        }

        $attachment = ProgressAttachment::where('progress_id', $progressId)->first();

        if (!$attachment) {
            return response()->json(['message' => 'Attachment not found'], 404);
        }`;

const newContent = content.replace(oldMethod, newMethod);

fs.writeFileSync('app/Http/Controllers/Api/ProgressController.php', newContent);

console.log('Done');

