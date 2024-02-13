<?php
/*
Plugin Name: * Transcript Editor
Description: A simple plugin to process and display transcripts for publishing online.
Version: 1.4
Author: Strong Anchor Tech
*/

// Additional function to send text to ChatGPT for editing
function edit_with_chatgpt($text, $model = 'gpt-3.5-turbo') {

    // Constructing the prompt
    $prompt = "I am giving you an excerpt from the exact transcript of a speech in English" .
              "Perform the following tasks: " .
              "1. Edit the text according to standard English punctuation rules. " .
              "2. Group the content logically into paragraphs with two sentences minimum per paragraph. " .
              "Each paragraph should be surrounded by HTML paragraph (p) tags. " .
              "Do not change or remove any of the original words except in cases of misspelling or obvious mistakes. " .
              "Here is the text: " . $text;

    // This assumes you have the chatGPT-interface plugin installed with the function of this name and a working API key saved in Tools > ChatGPT Interface
    return chatgpt_send_message($prompt, $model);
}

/*
 * Creates the shortcode [transcript_editor] to add the transcript editor to a page.
 */
function transcript_editor_shortcode() {
    ob_start(); // Start output buffering
    ?>
    <form method="post" action="">
        <textarea name="input_text" rows="10" cols="50"></textarea><br>
        <input type="submit" name="edit_transcript" value="Edit Transcript">
    </form>
    <?php
    if (isset($_POST['edit_transcript'])) {
        $processed_text = $_POST['input_text'];

        // Remove page numbers formatted as "Page X of Y"
        $processed_text = preg_replace('/Page \d+ of \d+/', '', $processed_text);

        // For equals signs
        $processed_text = preg_replace('/(\w)=+|=+(\w)/', '$1 $2', $processed_text);
        $processed_text = str_replace('=', '', $processed_text);

        // For sequences of dashes of any kind
        $processed_text = preg_replace('/(\w)(—+|-+)|=(—+|-+)(\w)/', '$1 $4', $processed_text);
        $processed_text = preg_replace('/—+|-+/', '', $processed_text);

        // For sequences of periods
        $processed_text = preg_replace('/\.\.+/', ' ', $processed_text);
        $processed_text = preg_replace('/…+/', ' ', $processed_text);
        		
        // Replace all instances of multiple spaces with a single space
        $processed_text = preg_replace('/\s+/', ' ', $processed_text);
		
		// Remove periods that are preceded by a space
        $processed_text = str_replace(' .', ' ', $processed_text);
		
		// Use esc_textarea() for sanitizing user-entered content
        $processed_text = esc_textarea($processed_text);

        // Make chunks of 1000-1500 characters (ending at the end of a sentence)
        $sentences = preg_split('/(?<=[.!?])\s+/', $processed_text, -1, PREG_SPLIT_NO_EMPTY);

		$current_length = 0;
        $chunk = '';
        $processed_text = '';
                
		foreach ($sentences as $sentence) {
            $sentence_length = mb_strlen($sentence);
            if (($current_length + $sentence_length) > 1500) {
                $edited_chunk = edit_with_chatgpt($chunk);
                if (!strpos($edited_chunk, '<p>')) {
                    $edited_chunk = '<p>' . $edited_chunk . '</p>';
                }
                $processed_text .= $edited_chunk;
                $chunk = '';
                $current_length = 0;
            }
            $chunk .= $sentence . ' ';
            $current_length += $sentence_length;
        }
        
        // Process the final chunk if there is any
        if (!empty($chunk)) {
            $edited_chunk = edit_with_chatgpt($chunk);
            if (!strpos($edited_chunk, '<p>')) {
                $edited_chunk = '<p>' . $edited_chunk . '</p>';
            }
            $processed_text .= $edited_chunk;
        }
    
        echo '<div id="output_area">';
        echo $processed_text;
        echo '</div>';
    }
    return ob_get_clean(); // Return the buffered content
}
add_shortcode('transcript_editor', 'transcript_editor_shortcode');

/*
 * Creates [custom_chatgpt] shortcode for iterating over a large body of text using a custom prompt
 */
function custom_chatgpt_shortcode() {
    ob_start(); ?>
    <form method="post" action="">
        <textarea name="input_text" rows="10" cols="50" placeholder="Enter text here"></textarea><br>
        <textarea name="custom_prompt" rows="2" cols="50" placeholder="Enter your prompt here"></textarea><br>
        <select name="model">
            <option value="gpt-3.5-turbo">GPT-3.5</option>
            <option value="gpt-4">GPT-4</option>
            <option value="gpt-4-turbo-preview">GPT-4 Turbo Preview</option>
        </select><br>
        <input type="submit" name="process_text" value="Process Text">
    </form>
    <?php
    if (isset($_POST['process_text'])) {
        $input_text = esc_textarea($_POST['input_text']);
        $custom_prompt = sanitize_text_field($_POST['custom_prompt']);
        $model = sanitize_text_field($_POST['model']); // Get the selected model

        $sentences = preg_split('/(?<=[.!?])\s+/', $input_text, -1, PREG_SPLIT_NO_EMPTY);
        $current_length = 0;
        $chunk = '';
        $processed_text = '';

        foreach ($sentences as $sentence) {
            $sentence_length = mb_strlen($sentence);
            if (($current_length + $sentence_length) > 1500) {
                $processed_text .= chatgpt_send_message($custom_prompt . "\n" . $chunk, $model);
                $chunk = '';
                $current_length = 0;
            }
            $chunk .= $sentence . ' ';
            $current_length += $sentence_length;
        }

        if (!empty($chunk)) {
            $processed_text .= chatgpt_send_message($custom_prompt . "\n" . $chunk, $model);
        }

        echo '<div id="output_area">' . $processed_text . '</div>';
    }
    return ob_get_clean();
}
add_shortcode('custom_chatgpt', 'custom_chatgpt_shortcode');

function add_paragraph_breaks_to_text($text) {
    $processed_text = '';
    $remainingText = $text;
    $error_messages = ''; // Initialize variable to accumulate error messages

    while (!empty($remainingText)) {
        // Extract the first 200 words for analysis
        $first200WordsArray = array_slice(explode(' ', $remainingText), 0, 200);
        $first200Words = implode(' ', $first200WordsArray);

        $prompt = "Given the following text, identify the number of sentences that should form the first paragraph. Provide a single number between 1 and 5 as your response, with no other commentary.\n\n" . $first200Words;
        
        $model = 'gpt-3.5-turbo'; // Using gpt-3.5-turbo model
        $response = chatgpt_send_message($prompt, $model);

        // Attempt to extract a single number from the response
        preg_match('/\b[1-5]\b/', $response, $matches);
        if (empty($matches)) {
            // If no valid number is found, log the error and proceed without breaking
            $error_messages .= '<strong>Error: Received unexpected response format from the API: </strong>' . htmlspecialchars($response) . ' <strong>For text: </strong>' . htmlspecialchars($first200Words) . '<br />';
            // Default to a conservative number of sentences if no valid response is received
            $length = 2;
        } else {
            $length = (int) $matches[0]; // Use the extracted number
        }

        $sentences = preg_split('/(?<=[.!?])\s+/', $remainingText, -1, PREG_SPLIT_NO_EMPTY);
        $paragraph = array_slice($sentences, 0, $length);
        $processed_text .= '<p>' . implode(' ', $paragraph) . '</p>';

        // Update remainingText for the next iteration
        $remainingText = implode(' ', array_slice($sentences, $length));
    }

    // Append any error messages to the bottom of the processed text
    if (!empty($error_messages)) {
        $processed_text .= "<div class='error-messages'>$error_messages</div>";
    }

    return $processed_text;
}

// [gpt_paragraph_breaks] Shortcode - ask for user input and display it with paragraph breaks
function gpt_paragraph_breaks_shortcode() {
    // Start output buffering to capture form and output
    ob_start();

    // Check if the form has been submitted
    if (isset($_POST['gpt_paragraph_breaks_submit'])) {
        $text = isset($_POST['gpt_paragraph_breaks_text']) ? stripslashes($_POST['gpt_paragraph_breaks_text']) : '';
        
        // Sanitize the text input
        $text = sanitize_textarea_field($text);

        // Process the text to add paragraph breaks
        $processed_text = add_paragraph_breaks_to_text($text);

        // Display the processed text
        echo '<div id="gpt_paragraph_breaks_result">' . $processed_text . '</div>';
    }

    // Display the form for text input
    ?>
    <form method="post" action="">
        <textarea name="gpt_paragraph_breaks_text" rows="10" cols="50" placeholder="Enter your text here"></textarea><br>
        <input type="submit" name="gpt_paragraph_breaks_submit" value="Add Paragraph Breaks">
    </form>
    <?php

    // Return the output buffer content
    return ob_get_clean();
}

// Register the shortcode with WordPress
add_shortcode('gpt_paragraph_breaks', 'gpt_paragraph_breaks_shortcode');
