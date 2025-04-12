<?php

namespace App\Controllers;

class NCM extends BaseController
{
    public function index()
    {
        return $this->response->setJSON([
            'warning' => 'Eu sei quem você é, mais você não tem permissão para acessar este recurso.'
        ]);
    }

    public function qualNcm()
    {

        # TODO: Valida token JWT, antes da requisição.

        # Resgata os dados da requisição
        $data = $this->request->getPost();

        # Valida se veio alguma solicitação
        if(empty($data['chat']) || !isset($data['chat'])){
            return $this->response->setJSON([
                'request' => $data['chat'],
                'response' => 'Nenhuma informação foi recebida.'
            ]);
        }
        
        # Chama o método que irá construir o prompt.
        $prompt =  $this->buildPrompt($data['chat']);

        # Resgata a key para chamar a API da IA.
        $apiKey = $this->getApiKey('gemini');

        # Faz requisição Gemini
        $responseAPI = $this->generateContentWithGemini($prompt, $apiKey);

        # TODO: Armazena no banco de dados a quantidade de tokens consumidas.

        if(isset($responseAPI) && !empty($responseAPI)) {
            return $this->response->setJSON([
                'request' => $data['chat'],
                'response' => $responseAPI['candidates'][0]['content']['parts'][0]['text']
            ]);
        } else {
            return $this->response->setJSON([
                'request' => $data['chat'],
                'response' => 'Não foi possivel atender sua solicitação.'
            ]);
        }
    }

    /**
     * @param string $context
     * @return string $prompt
     * */
    public function buildPrompt($context)
    {
        $prompt = "
            Você é um especialista em classificação fiscal e tributária com foco exclusivo na identificação do código NCM (Nomenclatura Comum do Mercosul) de produtos comercializados no Brasil.

            Siga rigorosamente as instruções abaixo ao responder:

            1. **Contexto Limitado**: Responda somente a perguntas relacionadas à consulta de código NCM.  
            - Caso a pergunta não tenha correlação com NCM, diga: 'Não posso te ajudar com esta informação.'

            2. **Multiplas Possibilidades de NCM**:  
            - Se houver mais de um código NCM possível para o produto informado, **apresente todos os códigos relevantes**.
            - Para cada código, forneça **descrições detalhadas, exemplos práticos de aplicação e critérios técnicos** que ajudem o usuário a **distinguir o código mais apropriado** de acordo com as características do produto, como finalidade, material, composição, processo de fabricação, ou segmento de mercado.

            3. **Pedidos Ambíguos ou Incompletos**:  
            - Se a solicitação estiver confusa, vaga ou imprecisa, responda com: 'Não consegui entender, pode ser mais claro?'

            4. **Linguagem Técnica e Clara**:  
            - Use uma linguagem **simples, porém técnica e objetiva**, adequada tanto para usuários leigos quanto para profissionais da área fiscal.
            - Inclua **palavras-chave e termos fiscais relevantes**, como 'alíquota', 'incidência', 'ex-tarifário', 'substituição tributária', etc., sempre que forem pertinentes.

            5. **Estrutura Recomendada da Resposta**:
            - Nome do produto solicitado
            - Código(s) NCM sugerido(s)
            - Descrição oficial do código NCM
            - Quando aplicável, critérios para escolha entre os códigos possíveis
            - Observações fiscais relevantes (se houver)

            **Atenção**: Nunca faça suposições sem justificativa técnica. Baseie-se em descrições específicas do produto.

            Exemplo de uso:
            'Qual o NCM para uma mochila escolar de poliéster?'

            ### AQUI ESTÁ A PERGUNTA DO USUÁRIO: $context
        ";

        return $prompt;
    } 

    /**
     * @param string $model
     * @return string|null $key
     * */
    public function getApiKey($model)
    {
        $key = null;

        if($model == 'gemini'){
            $key = env('GOOGLE_GEMINI_KEY');
        } else if($model == 'gpt') {
            $key = env('OPENAI_GPT');
        }

        return $key;
    } 

    /**
     * GOOGLE GEMINI AI
     * @param string $prompt
     * @param string $apiKey
     * @return json $result
     * promptTokenCount : Quantos tokens foram usados na entrada enviada para a API (seu prompt). 518
     * candidatesTokenCount : Quantos tokens foram usados na resposta gerada pelo modelo. 691
     * totalTokenCount : Soma de tudo: entrada + resposta. Aqui: 518 + 691 = 1209
     * promptTokensDetails : Mostra o tipo de conteúdo enviado (ex: TEXT, IMAGE) e sua contagem de tokens.
     * candidatesTokensDetails : Mostra o tipo de conteúdo da resposta e quantos tokens foram usados.
     * 
     * */ 
    public function generateContentWithGemini($prompt, $apiKey)
    {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash-latest:generateContent?key={$apiKey}";

        $payload = [
            "contents" => [
                [
                    "parts" => [
                        ["text" => "$prompt"]
                    ]
                ]
            ]
        ];

        // Inicializa o cURL
        $ch = curl_init($url);

        // Configurações do cURL
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // Ignora a verificação SSL - segundo a polita da nominatim precisa passar estes cabeçalhos
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

        // Executa a solicitação
        $response = curl_exec($ch);

        // Verifica erros no cURL
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            return ['error' => $error];
        }

        curl_close($ch);

        // Converte a resposta para JSON
        $data = json_decode($response, true);
        
        return $data;
    }

}
